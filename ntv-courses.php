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

// Add admin menu for logs
add_action('admin_menu', 'ntv_add_admin_menu');
function ntv_add_admin_menu() {
    add_menu_page(
        'NTV Import Logs',
        'NTV Logs',
        'manage_options',
        'ntv-logs',
        'ntv_display_logs',
        'dashicons-list-view'
    );
}

function ntv_display_logs() {
    ?>
    <div class="wrap">
        <h1>NTV Import Logs</h1>
        <div class="ntv-logs">
            <form method="post">
                <?php wp_nonce_field('ntv_clear_logs', 'ntv_clear_logs_nonce'); ?>
                <input type="submit" name="clear_logs" class="button button-secondary" value="Clear Logs" />
                <input type="submit" name="run_import" class="button button-primary" value="Run Import Now" />
            </form>
            <hr />
            <?php
            // Handle form submissions
            if (isset($_POST['clear_logs']) && check_admin_referer('ntv_clear_logs', 'ntv_clear_logs_nonce')) {
                delete_option('ntv_import_logs');
                echo '<div class="notice notice-success"><p>Logs cleared successfully.</p></div>';
            }
            if (isset($_POST['run_import']) && check_admin_referer('ntv_clear_logs', 'ntv_clear_logs_nonce')) {
                ntv_run_import_process();
                echo '<div class="notice notice-info"><p>Import process started.</p></div>';
            }

            $logs = get_option('ntv_import_logs', array());
            if (empty($logs)) {
                echo '<p>No logs available yet.</p>';
            } else {
                echo '<table class="widefat">';
                echo '<thead><tr><th>Date</th><th>Message</th></tr></thead><tbody>';
                foreach (array_reverse($logs) as $log) {
                    echo '<tr>';
                    echo '<td>' . esc_html($log['date']) . '</td>';
                    echo '<td>' . esc_html($log['message']) . '</td>';
                    echo '</tr>';
                }
                echo '</tbody></table>';
            }
            ?>
        </div>
    </div>
    <?php
}

function ntv_log_message($message) {
    $logs = get_option('ntv_import_logs', array());
    $logs[] = array(
        'date' => current_time('Y-m-d H:i:s'),
        'message' => $message
    );
    
    // Keep only last 100 logs
    if (count($logs) > 100) {
        $logs = array_slice($logs, -100);
    }
    
    update_option('ntv_import_logs', $logs);
    error_log('NTV Import: ' . $message);
}

// Add weekly schedule
add_filter('cron_schedules', 'ntv_add_weekly_schedule');
function ntv_add_weekly_schedule($schedules) {
    if (!isset($schedules['weekly'])) {
        $schedules['weekly'] = [
            'interval' => 7 * 24 * 60 * 60,
            'display'  => __('Once Weekly')
        ];
    }
    return $schedules;
}

// Plugin activation
register_activation_hook(__FILE__, 'ntv_activate');
function ntv_activate() {
    ntv_log_message('Plugin activated - starting initial import');
    
    // Run initial import
    ntv_run_import_process();
    
    // Schedule weekly runs if not already scheduled
    if (!wp_next_scheduled('ntv_weekly_import')) {
        wp_schedule_event(time(), 'weekly', 'ntv_weekly_import');
        ntv_log_message('Weekly import schedule created');
    }
}

// Hook for scheduled runs
add_action('ntv_weekly_import', 'ntv_run_import_process');

function ntv_run_import_process() {
    ntv_log_message('Starting import process');
    
    // Step 1: Fetch and save courses
    ntv_log_message('Step 1: Fetching courses');
    $courses_response = ntv_courses_fetch_data();
    if (!$courses_response) {
        ntv_log_message('Failed to fetch courses data');
        return;
    }
    
    $upload_dir = wp_upload_dir();
    $import_dir = $upload_dir['basedir'] . '/wpallimport/files';
    wp_mkdir_p($import_dir);
    
    $temp_courses = $import_dir . '/temp_courses.xml';
    if (!file_put_contents($temp_courses, $courses_response)) {
        ntv_log_message('Failed to save courses data');
        return;
    }
    ntv_log_message('Courses data saved to temp file');
    
    // Step 2: Fetch and save events
    ntv_log_message('Step 2: Fetching events');
    $events_response = ntv_events_fetch_data();
    if (!$events_response) {
        ntv_log_message('Failed to fetch events data');
        @unlink($temp_courses);
        return;
    }
    
    $temp_events = $import_dir . '/temp_events.xml';
    if (!file_put_contents($temp_events, $events_response)) {
        ntv_log_message('Failed to save events data');
        @unlink($temp_courses);
        return;
    }
    ntv_log_message('Events data saved to temp file');
    
    // Step 3: Check for duplicates and merge
    ntv_log_message('Step 3: Checking for duplicates and merging');
    if (!ntv_merge_data()) {
        ntv_log_message('Failed to merge data');
        @unlink($temp_courses);
        @unlink($temp_events);
        return;
    }
    
    ntv_log_message('Import process completed successfully');
}

function ntv_courses_fetch_data() {
    ntv_log_message('Fetching courses from API');
    
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
        ntv_log_message("Courses API request failed with HTTP code: " . $http_code);
        return null;
    }

    return $response ?: null;
}

function ntv_events_fetch_data() {
    ntv_log_message('Fetching events from API');
    
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
        ntv_log_message("Events API request failed with HTTP code: " . $http_code);
        return null;
    }

    return $response ?: null;
}

function ntv_merge_data() {
    $upload_dir = wp_upload_dir();
    $import_dir = $upload_dir['basedir'] . '/wpallimport/files';
    $temp_courses = $import_dir . '/temp_courses.xml';
    $temp_events = $import_dir . '/temp_events.xml';
    $final_file = $import_dir . '/courses.xml';

    // Check if both files exist
    if (!file_exists($temp_courses) || !file_exists($temp_events)) {
        ntv_log_message('One or both temp files missing');
        return false;
    }

    // Enable user error handling
    libxml_use_internal_errors(true);

    // Load and clean XML files
    $courses_content = file_get_contents($temp_courses);
    $events_content = file_get_contents($temp_events);
    
    ntv_log_message('Raw courses content length: ' . strlen($courses_content));
    ntv_log_message('Raw events content length: ' . strlen($events_content));
    
    // Extract data from SOAP responses
    $courses_content = preg_replace('/<s:Envelope[^>]*>.*?<GetAllCoursesResult>(.*?)<\/GetAllCoursesResult>.*?<\/s:Envelope>/s', '$1', $courses_content);
    $events_content = preg_replace('/<s:Envelope[^>]*>.*?<GetAllEventsResult>(.*?)<\/GetAllEventsResult>.*?<\/s:Envelope>/s', '$1', $events_content);
    
    ntv_log_message('Extracted courses content length: ' . strlen($courses_content));
    ntv_log_message('Extracted events content length: ' . strlen($events_content));
    
    // Save extracted content for debugging
    file_put_contents($import_dir . '/debug_courses.xml', $courses_content);
    file_put_contents($import_dir . '/debug_events.xml', $events_content);
    
    // Load XML
    $courses_xml = simplexml_load_string($courses_content);
    $events_xml = simplexml_load_string($events_content);

    if (!$courses_xml || !$events_xml) {
        $errors = libxml_get_errors();
        foreach ($errors as $error) {
            ntv_log_message("XML Error: " . $error->message . " at line " . $error->line);
        }
        libxml_clear_errors();
        return false;
    }

    // Track duplicates
    $course_codes = array();
    $event_ids = array();
    $duplicates_found = false;

    // Check for duplicate courses
    foreach ($courses_xml->xpath('//Courses') as $course) {
        $code = (string)$course->code;
        if (isset($course_codes[$code])) {
            ntv_log_message("Duplicate course found: " . $code);
            $duplicates_found = true;
        }
        $course_codes[$code] = true;
    }

    // Check for duplicate events
    foreach ($events_xml->xpath('//Events') as $event) {
        $id = (int)$event->id;
        if (isset($event_ids[$id])) {
            ntv_log_message("Duplicate event found: " . $id);
            $duplicates_found = true;
        }
        $event_ids[$id] = true;
    }

    if ($duplicates_found) {
        ntv_log_message('Duplicates found - check logs for details');
    }

    // Create merged XML
    $merged_xml = new SimpleXMLElement('<?xml version="1.0" encoding="UTF-8"?><data></data>');
    $courses_node = $merged_xml->addChild('courses');
    $events_node = $merged_xml->addChild('events');

    // Add unique courses
    $course_count = 0;
    foreach ($courses_xml->xpath('//Courses') as $course) {
        $code = (string)$course->code;
        if (isset($course_codes[$code])) {
            $course_node = $courses_node->addChild('course');
            foreach ($course->children() as $child) {
                $course_node->addChild($child->getName(), htmlspecialchars((string)$child));
            }
            unset($course_codes[$code]); // Ensure we only add it once
            $course_count++;
        }
    }
    ntv_log_message("Added {$course_count} unique courses");

    // Add unique events with matching courses
    $event_count = 0;
    foreach ($events_xml->xpath('//Events') as $event) {
        $id = (int)$event->id;
        $code = (string)$event->code;
        if (isset($event_ids[$id]) && isset($course_codes[$code])) {
            $event_node = $events_node->addChild('event');
            foreach ($event->children() as $child) {
                $event_node->addChild($child->getName(), htmlspecialchars((string)$child));
            }
            unset($event_ids[$id]); // Ensure we only add it once
            $event_count++;
        }
    }
    ntv_log_message("Added {$event_count} unique events");

    // Save merged file
    $merged_content = $merged_xml->asXML();
    ntv_log_message('Merged content length: ' . strlen($merged_content));
    
    if (!file_put_contents($final_file, $merged_content)) {
        ntv_log_message('Failed to save merged file');
        return false;
    }

    // Clean up temp files
    @unlink($temp_courses);
    @unlink($temp_events);
    
    ntv_log_message('Merge completed successfully');
    return true;
}

// Plugin deactivation
register_deactivation_hook(__FILE__, 'ntv_deactivate');
function ntv_deactivate() {
    ntv_log_message('Plugin deactivated');
    wp_clear_scheduled_hook('ntv_weekly_import');
    
    // Clean up files
    $upload_dir = wp_upload_dir();
    $import_dir = $upload_dir['basedir'] . '/wpallimport/files';
    $files_to_clean = [
        $import_dir . '/temp_courses.xml',
        $import_dir . '/temp_events.xml',
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
