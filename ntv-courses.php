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

        $temp_xml_path = wp_upload_dir()['basedir'] . '/courses/temp.xml';
        $csv_path = wp_upload_dir()['basedir'] . '/courses/courses.csv';

        // Ensure directory exists
        wp_mkdir_p(dirname($temp_xml_path));

        // Save XML response to temporary file
        file_put_contents($temp_xml_path, $xml_response);

        // Convert XML to CSV
        if (!ntv_courses_convert_to_csv($temp_xml_path, $csv_path)) {
            throw new Exception('Failed to convert XML to CSV');
        }

        // Clean up temporary XML file
        unlink($temp_xml_path);

    } catch (Exception $e) {
        ntv_courses_log_error($e->getMessage());
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

        // Extract and write headers
        $first_course = current($courses);
        $headers = array_keys(get_object_vars($first_course));
        fputcsv($fp, $headers);

        // Write data
        foreach ($courses as $course) {
            $row = [];
            foreach ($headers as $header) {
                $row[] = (string)$course->$header;
            }
            fputcsv($fp, $row);
        }

        fclose($fp);
        return true;

    } catch (Exception $e) {
        ntv_courses_log_error("XML to CSV conversion failed: " . $e->getMessage());
        return false;
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

function ntv_fetch_courses_to_csv($output_directory = null) {
    // Set default output directory if none provided
    if (!$output_directory) {
        $output_directory = __DIR__ . '/courses';
    }

    try {
        // Ensure directory exists
        if (!is_dir($output_directory)) {
            mkdir($output_directory, 0755, true);
        }

        // Define file paths
        $temp_xml_path = $output_directory . '/temp.xml';
        $csv_path = $output_directory . '/courses.csv';
        
        // Fetch XML data
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
            throw new Exception("CURL Error: $error");
        }

        if (!$response) {
            throw new Exception('No response received from API');
        }

        // Save XML response to temporary file
        file_put_contents($temp_xml_path, $response);

        // Convert XML to CSV
        $xml = new SimpleXMLElement(file_get_contents($temp_xml_path));
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

        // Extract and write headers
        $first_course = current($courses);
        $headers = array_keys(get_object_vars($first_course));
        fputcsv($fp, $headers);

        // Write data
        foreach ($courses as $course) {
            $row = [];
            foreach ($headers as $header) {
                $row[] = (string)$course->$header;
            }
            fputcsv($fp, $row);
        }

        fclose($fp);

        // Clean up temporary XML file
        unlink($temp_xml_path);

        return [
            'success' => true,
            'message' => 'Courses successfully exported to CSV',
            'csv_path' => $csv_path
        ];

    } catch (Exception $e) {
        // Log error
        $log_path = $output_directory . '/error.log';
        $log_entry = sprintf(
            "[%s] %s\n",
            date('Y-m-d H:i:s'),
            $e->getMessage()
        );
        
        file_put_contents(
            $log_path,
            $log_entry,
            FILE_APPEND | LOCK_EX
        );

        return [
            'success' => false,
            'message' => $e->getMessage(),
            'log_path' => $log_path
        ];
    }
}

// Initialize the plugin
add_action('plugins_loaded', 'ntv_courses_init');