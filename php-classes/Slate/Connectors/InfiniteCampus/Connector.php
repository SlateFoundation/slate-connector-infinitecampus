<?php

namespace Slate\Connectors\InfiniteCampus;

use Exception;
use SpreadsheetReader;

use Emergence\Connectors\Mapping;
use Emergence\Connectors\Exceptions\RemoteRecordInvalid;
use Emergence\People\User;

use Psr\Log\LogLevel;

use Slate\Term;
use Slate\Courses\Course;
use Slate\Courses\Section;
use Slate\People\Student;
use Emergence\Connectors\IJob;


class Connector extends \Slate\Connectors\AbstractSpreadsheetConnector implements \Emergence\Connectors\ISynchronize
{
    public static $personNameMappings = [];
    public static $courseNameMappings = [];
    public static $teacherPlaceholders = ['TBD, Teacher', 'X Lunch', 'Y Lunch'];
    public static $endOfYearUnenrollmentThreshold = 14; // days from end of school year in which an unenrollment will be ignored

    // AbstractConnector overrides
    public static $title = 'Infinite Campus';
    public static $connectorId = 'infinite-campus';
    public static $getSectionTerm;

    public static $studentsGraduationYearGroups = true;

    public static $studentColumns = [
        'Student Number' => 'StudentNumber',
        'Homeroom Teacher' => 'AdvisorFullName'
    ];

    public static $studentRequiredColumns = [
        'Gender',
        'Grade',
        'AdvisorFullName'
    ];

    public static $sectionColumns = [
        'Course ID' => 'CourseExternal',
        'Course Name' => 'CourseTitle',
        'Section ID' => 'SectionExternal',
        'Max Students' => 'StudentsCapacity',
        'Teacher Display' => 'TeacherFullName[]',
        'Teacher 2  Display' => 'TeacherFullName[]',
        'Room Name' => 'Location',
        'Terms' => 'TermQuarters',
        'Term End' => 'TermLastQuarter',
        'Band' => 'Schedule',
        'Section Template Group' => 'Schedule'
    ];

    public static $sectionRequiredColumns = [
        'CourseCode' => false,
        //'CourseExternal',
        'CourseTitle',
        'SectionExternal',
        'TermQuarters',
        'TermLastQuarter',
        'Schedule'
    ];

    public static $enrollmentColumns = [
        // discard extra columns
        'Course ID' => false,
        'Section Number' => false,
        'End Year' => false,
        'End Date' => 'EndDate'
    ];


    // workflow implementations
    protected static function _getJobConfig(array $requestData)
    {
        $config = parent::_getJobConfig($requestData);

        $config['updatePasswords'] = false;
        $config['updateAbout'] = false;
        $config['matchFullNames'] = false;
        $config['autoAssignEmail'] = true;

        $config['studentsCsv'] = !empty($_FILES['students']) && $_FILES['students']['error'] === UPLOAD_ERR_OK ? $_FILES['students']['tmp_name'] : null;
        $config['sectionsCsv'] = !empty($_FILES['sections']) && $_FILES['sections']['error'] === UPLOAD_ERR_OK ? $_FILES['sections']['tmp_name'] : null;
        $config['enrollmentsCsv'] = !empty($_FILES['schedules']) && $_FILES['schedules']['error'] === UPLOAD_ERR_OK ? $_FILES['schedules']['tmp_name'] : null;

        $config['autoCreateCourse'] = !empty($requestData['autoCreateCourse']);

        return $config;
    }

    public static function synchronize(IJob $Job, $pretend = true)
    {
        if ($Job->Status != 'Pending' && $Job->Status != 'Completed') {
            return static::throwError('Cannot execute job, status is not Pending or Complete');
        }

        // update job status
        $Job->Status = 'Pending';

        if (!$pretend) {
            $Job->save();
        }


        // init results struct
        $results = [];


        // execute tasks based on available spreadsheets
        if (!empty($Job->Config['studentsCsv'])) {
            $results['pull-students'] = static::pullStudents(
                $Job,
                SpreadsheetReader::createFromStream(fopen($Job->Config['studentsCsv'], 'r')),
                $pretend
            );
        }

        if (!empty($Job->Config['sectionsCsv'])) {
            $results['pull-sections'] = static::pullSections(
                $Job,
                SpreadsheetReader::createFromStream(fopen($Job->Config['sectionsCsv'], 'r')),
                $pretend
            );
        }

        if (!empty($Job->Config['enrollmentsCsv'])) {
            $results['pull-enrollments'] = static::pullEnrollments(
                $Job,
                SpreadsheetReader::createFromStream(fopen($Job->Config['enrollmentsCsv'], 'r')),
                $pretend
            );
        }

        // save job results
        $Job->Status = 'Completed';
        $Job->Results = $results;

        if (!$pretend) {
            $Job->save();
        }

        return true;
    }

    protected static function _readStudent(IJob $Job, array $row)
    {
        $row = static::_readRow($row, static::getStackedConfig('studentColumns'));

        if (isset($row['AdvisorFullName'])) {

            if (!empty(static::$personNameMappings[$row['AdvisorFullName']])) {
                $row['AdvisorFullName'] = static::$personNameMappings[$row['AdvisorFullName']];
            }

            if (preg_match("/([a-z\-\']+),\s([a-z\-\']+)/i", $row['AdvisorFullName'], $matches)) {
                $row['AdvisorLastName'] = $matches[1];
                $row['AdvisorFirstName'] = $matches[2];
            }
        }

        static::_fireEvent('readStudent', [
            'Job' => $Job,
            'row' => &$row
        ]);

        return $row;
    }

    protected static function getSectionTeachers(IJob $Job, Section $Section, array $row)
    {
        $teachers = [];

        foreach ($row['TeacherFullName'] as $i => $fullName) {
            if (in_array($fullName, static::$teacherPlaceholders)) {
                continue;
            }

            if (count($split = explode('/', $fullName)) > 1) {
                foreach ($split as $lastName) {
                    $Teacher = User::getByWhere([
                        'AccountLevel IN ("Teacher", "Administrator")',
                        'LastName' => $lastName
                    ]);

                    if (!$Teacher) {
                        throw new RemoteRecordInvalid(
                            'teacher-not-found-by-last',
                            'Teacher not found for last name: '.$lastName,
                            $row,
                            $lastName
                        );
                    }

                    $teachers[] = $Teacher;
                }

                continue;
            }

            if (count($split = explode(',', $fullName)) == 2) {
                if ($i == 0) {
                    $fullName = trim($split[1]) . ' ' . trim($split[0]);
                } else {
                    $fullName = trim($split[0]);
                }
            }

            if (!empty(static::$personNameMappings[$fullName])) {
                $fullName = static::$personNameMappings[$fullName];
            }

            $splitName = preg_split('/\s+/', $fullName);
            $firstName = array_shift($splitName);
            $lastName = array_pop($splitName);

            if (!$teachers[] = User::getByFullName($firstName, $lastName)) {
                throw new RemoteRecordInvalid(
                    'teacher-not-found-by-name',
                    'Teacher not found for full name: '.$fullName,
                    $row,
                    $fullName
                );
            }
        }

        return $teachers;
    }


    protected static function getSectionCourse(IJob $Job, Section $Section, array $row)
    {
        $courseTitle = $row['CourseTitle'];
        if (!empty(static::$courseNameMappings[$courseTitle])) {
            $courseTitle = static::$courseNameMappings[$courseTitle];
        }

        if ($Course = Course::getByCode($row['CourseCode'])) {
            return $Course;
        } else if ($Course = Course::getByField('Title', $courseTitle)) {
            return $Course;
        } else if (!empty($Job->Config['autoCreateCourse'])) {
            return Course::create([
                'Code' => $row['CourseCode'],
                'Title' => $courseTitle ?: $row['CourseCode'],
                'Department' => !empty($row['DepartmentTitle']) ? Department::getOrCreateByTitle($row['DepartmentTitle']) : null
            ]);
        } else {
            throw new RemoteRecordInvalid(
                'course-not-found',
                'course not found for title: '.$courseTitle,
                $row,
                $courseTitle
            );
        }

    }

    protected static function _readEnrollment(IJob $Job, array $row)
    {
        $row = parent::_readEnrollment($Job, $row);


        if (!empty($row['EndDate'])) {
            $enrollmentEndDate = strtotime($row['EndDate']);
            $termEndDate = strtotime($Job->getMasterTerm()->EndDate);
            $daysFromGraduation = abs(($termEndDate - $enrollmentEndDate) / 60 / 60 / 24);

            if (static::$endOfYearUnenrollmentThreshold && $daysFromGraduation < static::$endOfYearUnenrollmentThreshold) {
                $row['EndDate'] = '';
            }
        }

        return $row;
    }
}
