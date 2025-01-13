<?php
/**
 * Plugin Name: NTV Courses & Events
 * Description: Fetches and stores XML data for courses and events from QA, runs every 7 days
 * Version: 1.0.0
 * Author: Your Name
 */

if (!defined('ABSPATH')) {
    exit;
}

// Add a weekly schedule (every 7 days)
add_filter('cron_schedules', 'ntv_courses_add_weekly_schedule');
function ntv_courses_add_weekly_schedule($schedules) {
    if (!isset($schedules['weekly'])) {
        $schedules['weekly'] = [
            'interval' => 7 * 24 * 60 * 60, // 7 days in seconds
            'display'  => __('Once Weekly')
        ];
    }
    return $schedules;
}

// On plugin activation, schedule the event if it doesn't exist and run once immediately
register_activation_hook(__FILE__, 'ntv_courses_activate');
function ntv_courses_activate() {
    if (!wp_next_scheduled('ntv_courses_cron_event')) {
        wp_schedule_event(time(), 'weekly', 'ntv_courses_cron_event');
    }
    ntv_fetch_all_data(); // Run once immediately
}

// Hook the cron event to our fetching function
add_action('ntv_courses_cron_event', 'ntv_fetch_all_data');

function ntv_clean_xml_content($content) {
    // First, decode all HTML entities to their actual characters
    $content = html_entity_decode($content, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    
    // Fix specific problematic patterns
    $content = str_replace([
        '&#x2;', // Common problematic hex
        '&amp;#x2;', // Encoded version
        '&lt;', // HTML less than
        '&gt;', // HTML greater than
        '&amp;lt;', // Double encoded less than
        '&amp;gt;', // Double encoded greater than
    ], [
        '-', // Replace with hyphen
        '-',
        '<', // Replace with actual characters
        '>',
        '<',
        '>'
    ], $content);
    
    // Replace any remaining hex entities with hyphens
    $content = preg_replace('/&#x[0-9a-fA-F]+;/', '-', $content);
    
    // Clean up any double-encoded ampersands
    $content = str_replace('&amp;amp;', '&amp;', $content);
    
    // Properly encode remaining ampersands
    $content = preg_replace('/&(?!(?:amp|lt|gt|quot|apos);)/', '&amp;', $content);
    
    // Remove any other invalid XML characters
    $content = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $content);
    
    // Ensure all tags are properly closed
    $content = preg_replace('/<(br|hr|img)([^>]*[^\/])>/', '<$1$2/>', $content);
    
    // Add CDATA sections around HTML content fields
    $content = preg_replace_callback('/<(overview|prerequisites|objectives|outline|specialNotices)>(.*?)<\/\1>/s',
        function($matches) {
            return "<{$matches[1]}><![CDATA[" . trim($matches[2]) . "]]></{$matches[1]}>";
        },
        $content
    );
    
    return $content;
}

function ntv_save_xml_response($response, $filename) {
    // Clean the XML before saving
    $cleaned_response = ntv_clean_xml_content($response);
    
    // Set up the wpallimport directory path
    $upload_dir = wp_upload_dir();
    $import_dir = $upload_dir['basedir'] . '/wpallimport/files';
    $temp_file = $import_dir . '/temp_' . $filename;
    $final_file = $import_dir . '/' . $filename;
    
    // Ensure the directory exists
    wp_mkdir_p($import_dir);
    
    // Save to temp file first
    if (file_put_contents($temp_file, $cleaned_response)) {
        // If temp save successful, move to final location
        if (rename($temp_file, $final_file)) {
            return true;
        }
    }
    
    return false;
}

function ntv_fetch_all_data() {
    // Step 1: Fetch Courses
    $courses_response = ntv_courses_fetch_data();
    if ($courses_response) {
        ntv_save_xml_response($courses_response, 'courses_temp.xml');
    }

    // Step 2: Fetch Events
    $events_response = ntv_events_fetch_data();
    if ($events_response) {
        ntv_save_xml_response($events_response, 'events_temp.xml');
    }

    // Step 3: Merge Data if both files exist
    if ($courses_response && $events_response) {
        ntv_merge_data();
    }
}

function ntv_courses_fetch_data() {
    $soap_url = 'https://extranet.qa.com/Services/Courses.svc';
    $api_key = 'ABA1665B-58B6-4FBD-BD70-F2349799C77F';

    $soap_envelope = sprintf(
        '<?xml version="1.0" encoding="UTF-8"?>
        <soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/" xmlns:tem="http://tempuri.org/">
            <soapenv:Header/>
            <soapenv:Body>
                <tem:GetAllCourses>
                    <tem:key>%s</tem:key>
                    <tem:version>1</tem:version>
                </tem:GetAllCourses>
            </soapenv:Body>
        </soapenv:Envelope>',
        $api_key
    );

    $curl = curl_init();
    curl_setopt_array($curl, [
        CURLOPT_URL => $soap_url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $soap_envelope,
        CURLOPT_HTTPHEADER => [
            'Content-Type: text/xml; charset=utf-8',
            'SOAPAction: http://tempuri.org/Courses/GetAllCourses'
        ]
    ]);

    $response = curl_exec($curl);
    $http_code = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);

    if ($http_code !== 200) {
        error_log("NTV Courses: Failed to fetch courses. HTTP Code: " . $http_code);
        return null;
    }

    return $response ?: null;
}

function ntv_events_fetch_data() {
    $soap_url = 'https://extranet.qa.com/Services/Events.svc';
    $api_key = 'ABA1665B-58B6-4FBD-BD70-F2349799C77F';

    $soap_envelope = sprintf(
        '<?xml version="1.0" encoding="UTF-8"?>
        <soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/" xmlns:tem="http://tempuri.org/">
            <soapenv:Header/>
            <soapenv:Body>
                <tem:GetAllEvents>
                    <tem:key>%s</tem:key>
                    <tem:version>1</tem:version>
                </tem:GetAllEvents>
            </soapenv:Body>
        </soapenv:Envelope>',
        $api_key
    );

    $curl = curl_init();
    curl_setopt_array($curl, [
        CURLOPT_URL => $soap_url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $soap_envelope,
        CURLOPT_HTTPHEADER => [
            'Content-Type: text/xml; charset=utf-8',
            'SOAPAction: http://tempuri.org/Events/GetAllEvents'
        ]
    ]);

    $response = curl_exec($curl);
    $http_code = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);

    if ($http_code !== 200) {
        error_log("NTV Events: Failed to fetch events. HTTP Code: " . $http_code);
        return null;
    }

    return $response ?: null;
}

function ntv_merge_data() {
    $upload_dir = wp_upload_dir();
    $import_dir = $upload_dir['basedir'] . '/wpallimport/files';
    $courses_path = $import_dir . '/courses_temp.xml';
    $events_path = $import_dir . '/events_temp.xml';
    $merged_path = $import_dir . '/courses.xml';

    // Check if both files exist
    if (!file_exists($courses_path) || !file_exists($events_path)) {
        error_log('NTV Merger: One or both source files missing');
        return;
    }

    // Enable user error handling
    libxml_use_internal_errors(true);

    // Load and clean XML files
    $courses_content = file_get_contents($courses_path);
    $events_content = file_get_contents($events_path);
    
    // Extract just the data portion from SOAP responses
    $courses_content = preg_replace('/<s:Envelope[^>]*>.*?<GetAllCoursesResult>(.*?)<\/GetAllCoursesResult>.*?<\/s:Envelope>/s', '$1', $courses_content);
    $events_content = preg_replace('/<s:Envelope[^>]*>.*?<GetAllEventsResult>(.*?)<\/GetAllEventsResult>.*?<\/s:Envelope>/s', '$1', $events_content);
    
    $courses_content = ntv_clean_xml_content($courses_content);
    $events_content = ntv_clean_xml_content($events_content);
    
    // Try to load the cleaned XML
    $courses_xml = simplexml_load_string($courses_content, 'SimpleXMLElement', LIBXML_NOCDATA | LIBXML_NOWARNING);
    $events_xml = simplexml_load_string($events_content, 'SimpleXMLElement', LIBXML_NOCDATA | LIBXML_NOWARNING);

    // Check for errors
    if (!$courses_xml || !$events_xml) {
        $errors = libxml_get_errors();
        foreach ($errors as $error) {
            error_log("XML Error: " . $error->message . " at line " . $error->line);
            // Save problematic content for debugging
            file_put_contents($import_dir . '/debug_courses.xml', $courses_content);
            file_put_contents($import_dir . '/debug_events.xml', $events_content);
        }
        libxml_clear_errors();
        return;
    }

    // Create new XML document for merged data
    $merged_xml = new SimpleXMLElement('<?xml version="1.0" encoding="UTF-8"?><data></data>');

    // Create a courses section
    $courses_node = $merged_xml->addChild('courses');
    
    // Track unique courses by code
    $course_map = [];
    $processed_courses = [];
    
    // First pass: collect all course data and check for duplicates
    foreach ($courses_xml->xpath('//Courses') as $course) {
        $course_code = (string)$course->code;
        
        // Skip if we've already processed this course code
        if (isset($processed_courses[$course_code])) {
            error_log("NTV Merger: Duplicate course found with code: " . $course_code);
            continue;
        }
        
        $processed_courses[$course_code] = true;
        
        $course_node = $courses_node->addChild('course');
        
        // Add all course fields
        $course_node->addChild('code', htmlspecialchars($course_code));
        $course_node->addChild('title', htmlspecialchars((string)$course->title));
        $course_node->addChild('listPrice', (float)$course->listPrice);
        $course_node->addChild('duration', (float)$course->duration);
        $course_node->addChild('practice', htmlspecialchars((string)$course->practice));
        $course_node->addChild('subject', htmlspecialchars((string)$course->subject));
        $course_node->addChild('vendor', htmlspecialchars((string)$course->vendor));
        $course_node->addChild('techType', htmlspecialchars((string)$course->techType));
        
        // Add HTML content fields with CDATA
        $overview = $course_node->addChild('overview');
        $overview->addCData((string)$course->overview);
        
        $prerequisites = $course_node->addChild('prerequisites');
        $prerequisites->addCData((string)$course->prerequisites);
        
        $objectives = $course_node->addChild('objectives');
        $objectives->addCData((string)$course->objectives);
        
        $outline = $course_node->addChild('outline');
        $outline->addCData((string)$course->outline);
        
        $specialNotices = $course_node->addChild('specialNotices');
        $specialNotices->addCData((string)$course->specialNotices);
        
        $course_map[$course_code] = [
            'title' => (string)$course->title,
            'duration' => (float)$course->duration,
            'listPrice' => (float)$course->listPrice
        ];
    }

    // Create an events section
    $events_node = $merged_xml->addChild('events');
    
    // Track processed events to avoid duplicates
    $processed_events = [];
    
    // Add events and link them to courses
    foreach ($events_xml->xpath('//Events') as $event) {
        $event_code = (string)$event->code;
        $event_id = (int)$event->id;
        
        // Create a unique key for the event using both code and ID
        $event_key = $event_code . '_' . $event_id;
        
        // Skip if we've already processed this event or if it doesn't have a matching course
        if (isset($processed_events[$event_key]) || !isset($course_map[$event_code])) {
            if (isset($processed_events[$event_key])) {
                error_log("NTV Merger: Duplicate event found with ID: " . $event_id);
            }
            continue;
        }
        
        $processed_events[$event_key] = true;
        
        $event_node = $events_node->addChild('event');
        
        // Add event specific fields
        $event_node->addChild('id', $event_id);
        $event_node->addChild('masterId', (int)$event->masterId);
        $event_node->addChild('code', htmlspecialchars($event_code));
        $event_node->addChild('start', htmlspecialchars((string)$event->start));
        $event_node->addChild('end', htmlspecialchars((string)$event->end));
        $event_node->addChild('locationId', (int)$event->locationId);
        $event_node->addChild('locationName', htmlspecialchars((string)$event->locationName));
        $event_node->addChild('rRP', (float)$event->rRP);
        $event_node->addChild('yourRRP', (float)$event->yourRRP);
        $event_node->addChild('duration', (float)$event->duration);
        $event_node->addChild('includesWeekends', htmlspecialchars((string)$event->includesWeekends));
        $event_node->addChild('residential', htmlspecialchars((string)$event->residential));
        $event_node->addChild('availability', htmlspecialchars((string)$event->availability));
        $event_node->addChild('bookable', htmlspecialchars((string)$event->bookable));
        
        // Add course reference data
        $course_ref = $event_node->addChild('courseReference');
        $course_ref->addChild('code', htmlspecialchars($event_code));
        $course_ref->addChild('title', htmlspecialchars($course_map[$event_code]['title']));
        $course_ref->addChild('duration', $course_map[$event_code]['duration']);
        $course_ref->addChild('listPrice', $course_map[$event_code]['listPrice']);
    }

    // Log summary of processed items
    error_log(sprintf(
        "NTV Merger: Processed %d unique courses and %d unique events",
        count($processed_courses),
        count($processed_events)
    ));

    // Create a temporary file for the merged content
    $temp_merged = $import_dir . '/temp_merged.xml';
    
    // Save to temp file first
    if ($merged_xml->asXML($temp_merged)) {
        // If temp save successful, move to final location
        if (rename($temp_merged, $merged_path)) {
            // Clean up temp files only after successful merge
            @unlink($courses_path);
            @unlink($events_path);
            return true;
        }
    }
    
    // If we get here, something went wrong
    error_log('NTV Merger: Failed to save merged XML');
    return false;
}

// Register deactivation hook
register_deactivation_hook(__FILE__, 'ntv_courses_deactivate');

function ntv_courses_deactivate() {
    wp_clear_scheduled_hook('ntv_courses_cron_event');
    
    // Clean up files
    $upload_dir = wp_upload_dir();
    $import_dir = $upload_dir['basedir'] . '/wpallimport/files';
    $files_to_clean = [
        $import_dir . '/courses_temp.xml',
        $import_dir . '/events_temp.xml',
        $import_dir . '/courses.xml',
        $import_dir . '/debug_courses.xml',
        $import_dir . '/debug_events.xml'
    ];
    
    foreach ($files_to_clean as $file) {
        if (file_exists($file)) {
            @unlink($file);
        }
    }
}
