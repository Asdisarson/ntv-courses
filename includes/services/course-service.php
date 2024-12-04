<?php

declare(strict_types=1);

namespace NTVCourses\Services;

use NTVCourses\Logs\ErrorEntry;
use NTVCourses\Logs\LogEntry;

final class CourseService
{
    private const SOAP_URL = 'https://extranet.qa.com/Services/Courses.svc';
    private const API_KEY = 'ABA1665B-58B6-4FBD-BD70-F2349799C77F';

    public function fetchCourses(): string|null
    {
        $curl = curl_init();
        
        $soapEnvelope = $this->buildSoapEnvelope();
        
        curl_setopt_array($curl, [
            CURLOPT_URL => self::SOAP_URL,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => '',
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => 'POST',
            CURLOPT_POSTFIELDS => $soapEnvelope,
            CURLOPT_HTTPHEADER => [
                'Content-Type: text/xml; charset=utf-8',
                'SOAPAction: http://tempuri.org/Courses/GetAllCourses'
            ]
        ]);

        $response = curl_exec($curl);
        
        if (curl_errno($curl)) {
            (new ErrorEntry(
                message: curl_error($curl),
                type: 'SOAP',
                url: self::SOAP_URL
            ))->write();
            
            curl_close($curl);
            return null;
        }
        
        curl_close($curl);
        
        (new LogEntry(
            method: 'SOAP',
            url: self::SOAP_URL,
            data: ['action' => 'GetAllCourses'],
            response: $response,
            type: 'api'
        ))->write();
        
        return $response;
    }

    private function buildSoapEnvelope(): string
    {
        return <<<XML
        <soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/" xmlns:tem="http://tempuri.org/">
            <soapenv:Header/>
            <soapenv:Body>
                <tem:GetAllCourses>
                    <tem:key>{self::API_KEY}</tem:key>
                    <tem:version>1</tem:version>
                </tem:GetAllCourses>
            </soapenv:Body>
        </soapenv:Envelope>
        XML;
    }
} 