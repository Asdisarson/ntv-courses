<?php
/**
 * Plugin Name: NTV Courses
 * Description: Weekly course data synchronization from QA to CSV
 * Version: 1.0.0
 * Author: Your Name
 */

declare(strict_types=1);

namespace NTVCourses;

use NTVCourses\Services\CourseService;
use NTVCourses\Converter\XmlToCsvConverter;
use NTVCourses\Logs\ErrorEntry;

if (!defined('ABSPATH')) {
    exit;
}

final class NTVCourses
{
    public function __construct()
    {
        $this->init();
    }

    private function init(): void
    {
        add_action('init', [$this, 'setupSchedule']);
        add_action('ntv_weekly_course_update', [$this, 'updateCourses']);
    }

    public function setupSchedule(): void
    {
        if (!wp_next_scheduled('ntv_weekly_course_update')) {
            wp_schedule_event(time(), 'weekly', 'ntv_weekly_course_update');
        }
    }

    public function updateCourses(): void
    {
        try {
            $courseService = new CourseService();
            $xmlResponse = $courseService->fetchCourses();
            
            if (!$xmlResponse) {
                throw new \RuntimeException('Failed to fetch course data');
            }
            
            $tempXmlPath = wp_upload_dir()['basedir'] . '/courses/temp.xml';
            $csvPath = wp_upload_dir()['basedir'] . '/courses/courses.csv';
            
            // Ensure directory exists
            wp_mkdir_p(dirname($tempXmlPath));
            
            // Save XML response to temporary file
            file_put_contents($tempXmlPath, $xmlResponse);
            
            // Convert XML to CSV
            $converter = new XmlToCsvConverter(
                xmlPath: $tempXmlPath,
                csvPath: $csvPath,
                rootElement: 'Courses'
            );
            
            if (!$converter->convert()) {
                throw new \RuntimeException('Failed to convert XML to CSV');
            }
            
            // Clean up temporary XML file
            unlink($tempXmlPath);
            
        } catch (\Throwable $e) {
            (new ErrorEntry(
                message: 'Weekly course update failed',
                type: 'CRON',
                context: ['error' => $e->getMessage()],
                exception: $e
            ))->write();
        }
    }
}

// Initialize plugin
new NTVCourses();
