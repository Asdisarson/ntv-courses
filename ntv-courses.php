<?php
/**
 * Plugin Name: NTV Courses
 * Description: Weekly course data synchronization from QA to CSV
 * Version: 1.0.0
 * Author: Your Name
 */

if (!defined('ABSPATH')) {
    exit;
}

function ntv_courses_init() {
    add_action('init', 'ntv_courses_setup_schedule');
    add_action('ntv_weekly_course_update', 'ntv_courses_update');
    
    // Run initial update
    ntv_courses_update();
}

function ntv_courses_setup_schedule() {
    if (!wp_next_scheduled('ntv_weekly_course_update')) {
        wp_schedule_event(time(), 'weekly', 'ntv_weekly_course_update');
    }
}

function ntv_courses_update() {
    try {
        $xml_response = ntv_courses_fetch_data();
        if (!$xml_response) {
            throw new Exception('Failed to fetch course data');
        }

        // Create directories
        $base_dir = wp_upload_dir()['basedir'] . '/courses';
        $responses_dir = $base_dir . '/responses';
        wp_mkdir_p($responses_dir);

        $temp_xml_path = $base_dir . '/temp.xml';
        $csv_path = $base_dir . '/courses.csv';

        // Save initial courses XML response
        $response_file = $responses_dir . '/courses_' . date('Y-m-d_H-i-s') . '.xml';
        file_put_contents($response_file, $xml_response);

        // Save XML response to temporary file for processing
        file_put_contents($temp_xml_path, $xml_response);

        // Convert XML to CSV
        if (!ntv_courses_convert_to_csv($temp_xml_path, $csv_path)) {
            throw new Exception('Failed to convert XML to CSV');
        }

        // Clean up temporary XML file
        unlink($temp_xml_path);

    } catch (Exception $e) {
        ntv_courses_log_error($e->getMessage());
        throw $e; // Re-throw to allow caller to handle the error
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
    $error = curl_error($curl);
    curl_close($curl);

    if ($error) {
        ntv_courses_log_error("CURL Error: $error");
        return null;
    }

    return $response;
}

function ntv_courses_convert_to_csv($xml_path, $csv_path) {
    try {
        $xml = new SimpleXMLElement(file_get_contents($xml_path));
        $courses = $xml->xpath('//Courses');
        
        if (empty($courses)) {
            throw new Exception('No courses found in XML');
        }

        $fp = fopen($csv_path, 'w');
        if (!$fp) {
            throw new Exception('Unable to create CSV file');
        }

        // Write UTF-8 BOM
        fwrite($fp, "\xEF\xBB\xBF");

        // Extract and write headers with additional event columns
        $first_course = current($courses);
        $headers = array_keys(get_object_vars($first_course));
        $headers = array_merge($headers, ['EventDates', 'EventLocations', 'EventPrices']);
        fputcsv($fp, $headers);

        // Write data
        foreach ($courses as $course) {
            try {
                $row = [];
                foreach ($headers as $header) {
                    if (in_array($header, ['EventDates', 'EventLocations', 'EventPrices'])) {
                        continue;
                    }
                    $row[] = (string)$course->$header;
                }
                
                // Fetch events for this course
                try {
                    $events = ntv_courses_fetch_events((string)$course->CourseCode);
                    
                    $event_dates = [];
                    $event_locations = [];
                    $event_prices = [];
                    
                    if ($events) {
                        foreach ($events as $event) {
                            $event_dates[] = $event['date'];
                            $event_locations[] = $event['location'];
                            $event_prices[] = $event['price'];
                        }
                    }
                    
                    $row[] = implode('|', $event_dates);
                    $row[] = implode('|', $event_locations);
                    $row[] = implode('|', $event_prices);
                    
                } catch (Exception $e) {
                    // Log the error but continue with empty event data
                    $row[] = '';
                    $row[] = '';
                    $row[] = '';
                    ntv_courses_log_error("Skipping events for course " . (string)$course->CourseCode . ": " . $e->getMessage());
                }
                
                fputcsv($fp, $row);
            } catch (Exception $e) {
                ntv_courses_log_error("Error processing course: " . $e->getMessage());
                continue;
            }
        }

        fclose($fp);
        return true;

    } catch (Exception $e) {
        ntv_courses_log_error("XML to CSV conversion failed: " . $e->getMessage());
        return false;
    }
}

function ntv_courses_fetch_events($course_code) {
    $soap_url = 'https://extranet.qa.com/Services/Events.svc';
    $api_key = 'ABA1665B-58B6-4FBD-BD70-F2349799C77F';
    
    // Create responses directory if it doesn't exist
    $responses_dir = wp_upload_dir()['basedir'] . '/courses/responses';
    wp_mkdir_p($responses_dir);

    $soap_envelope = sprintf(
        '<?xml version="1.0" encoding="UTF-8"?>
        <soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/" xmlns:tem="http://tempuri.org/">
            <soapenv:Header/>
            <soapenv:Body>
                <tem:GetEventsByCourseCode>
                    <tem:key>%s</tem:key>
                    <tem:courseCode>%s</tem:courseCode>
                    <tem:version>2</tem:version>
                </tem:GetEventsByCourseCode>
            </soapenv:Body>
        </soapenv:Envelope>',
        $api_key,
        $course_code
    );

    $curl = curl_init();
    curl_setopt_array($curl, [
        CURLOPT_URL => $soap_url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $soap_envelope,
        CURLOPT_HTTPHEADER => [
            'Content-Type: text/xml; charset=utf-8',
            'SOAPAction: http://tempuri.org/Events/GetEventsByCourseCode'
        ]
    ]);

    $response = curl_exec($curl);
    $http_code = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    $error = curl_error($curl);
    curl_close($curl);

    // Save response to file
    $response_file = $responses_dir . '/events_' . $course_code . '_' . date('Y-m-d_H-i-s') . '.xml';
    file_put_contents($response_file, $response);

    // Handle errors
    if ($error) {
        ntv_courses_log_error("CURL Error fetching events for course $course_code: $error");
        throw new Exception("CURL Error: $error");
    }

    if ($http_code !== 200) {
        ntv_courses_log_error("HTTP Error $http_code fetching events for course $course_code");
        throw new Exception("HTTP Error $http_code");
    }

    try {
        // Check if response contains SOAP fault
        if (strpos($response, '<faultcode>') !== false) {
            $xml = new SimpleXMLElement($response);
            $fault = $xml->xpath('//faultstring');
            $fault_message = $fault ? (string)$fault[0] : 'Unknown SOAP fault';
            ntv_courses_log_error("SOAP Fault for course $course_code: $fault_message");
            throw new Exception("SOAP Fault: $fault_message");
        }

        $xml = new SimpleXMLElement($response);
        $events = $xml->xpath('//Events');
        
        if (empty($events)) {
            ntv_courses_log_error("No events found for course $course_code");
            return [];
        }

        $formatted_events = [];
        foreach ($events as $event) {
            $formatted_events[] = [
                'date' => (string)$event->StartDate,
                'location' => (string)$event->Location,
                'price' => (string)$event->Price
            ];
        }

        return $formatted_events;

    } catch (Exception $e) {
        ntv_courses_log_error("Error parsing events for course $course_code: " . $e->getMessage());
        throw $e;
    }
}

function ntv_courses_log_error($message) {
    $log_dir = wp_upload_dir()['basedir'] . '/courses/logs';
    wp_mkdir_p($log_dir);
    
    $log_entry = sprintf(
        "[%s] %s\n",
        date('Y-m-d H:i:s'),
        $message
    );
    
    file_put_contents(
        $log_dir . '/error.log',
        $log_entry,
        FILE_APPEND | LOCK_EX
    );
}

// Initialize the plugin
add_action('plugins_loaded', 'ntv_courses_init');