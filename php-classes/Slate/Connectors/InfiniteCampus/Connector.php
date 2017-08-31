<?php

namespace Slate\Connectors\InfiniteCampus;

use Exception;
use SpreadsheetReader;

use Emergence\Connectors\Job;
use Emergence\Connectors\Mapping;
use Emergence\Connectors\Exceptions\RemoteRecordInvalid;
use Emergence\People\User;

use Psr\Log\LogLevel;

use Slate\Courses\Course;
use Slate\Courses\Section;
use Slate\People\Student;
use Slate\Term;


class Connector extends \Slate\Connectors\AbstractSpreadsheetConnector implements \Emergence\Connectors\ISynchronize
{
    public static $fullNameMappings = [];
    public static $teacherPlaceholders = ['TBD, Teacher', 'X Lunch', 'Y Lunch'];

    // AbstractConnector overrides
    public static $title = 'Infinite Campus';
    public static $connectorId = 'infinite-campus';

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
        'Band' => 'Schedule'
    ];

    public static $sectionRequiredColumns = [
        'CourseCode' => false,
        'CourseExternal',
        'CourseTitle',
        'SectionExternal',
        'TermQuarters',
        'TermLastQuarter',
        'Schedule'
    ];


    // workflow implementations
    protected static function _getJobConfig(array $requestData)
    {
        $config = parent::_getJobConfig($requestData);

        $config['studentsCsv'] = !empty($_FILES['students']) && $_FILES['students']['error'] === UPLOAD_ERR_OK ? $_FILES['students']['tmp_name'] : null;
        $config['sectionsCsv'] = !empty($_FILES['sections']) && $_FILES['sections']['error'] === UPLOAD_ERR_OK ? $_FILES['sections']['tmp_name'] : null;
        $config['schedulesCsv'] = !empty($_FILES['schedules']) && $_FILES['schedules']['error'] === UPLOAD_ERR_OK ? $_FILES['schedules']['tmp_name'] : null;

        return $config;
    }

    public static function synchronize(Job $Job, $pretend = true)
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

    protected static function _readStudent($Job, array $row)
    {
        $row = static::_readRow($row, static::getStackedConfig('studentColumns'));

        if (isset($row['AdvisorFullName']) && preg_match("/([a-z\-\']+),\s([a-z\-\']+)/i", $row['AdvisorFullName'], $matches)) {
            $row['AdvisorLastName'] = $matches[1];
            $row['AdvisorFirstName'] = $matches[2];
        }

        static::_fireEvent('readStudent', [
            'Job' => $Job,
            'row' => &$row
        ]);

        return $row;
    }

    protected static function getTeachers(Job $Job, array $row)
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

            if (!empty(static::$fullNameMappings[$fullName])) {
                $fullName = static::$fullNameMappings[$fullName];
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

        return $Teacher ? [$Teacher] : [];
    }

    protected static function _getTerm(Job $Job, array $row)
    {
        if (isset($row['Terms']) || isset($row['_rest']['Terms'])) {
            if (!$Term = Term::getClosest()) {
                return null;
            }

            $closestTermYear = (int)substr($Term->getMaster()->StartDate, 0, 4);
            $termEnd = $row['Term End'] ?: $row['_rest']['Term End'];
            $termLength = $row['Terms'] ?: $row['_rest']['Terms'];

            if ((int)$termLength === 1) { // handle quarter term
                $termHandle = sprintf('q%u-%u', $closestTermYear, $termEnd);
            } elseif ((int)$termLength === 2) { // handle semester term
                $termHandle = sprintf('s%u-%u', $closestTermYear, $termEnd % 2 === 0 ? $termEnd / 2 : null);
            } elseif ((int)$termLength === 4) { // handle year long term
                $termHandle = 'y'.$closestTermYear;
            }

            return Term::getByHandle($termHandle);
        }
        return null;
    }

    protected static function _getCourse($Job, array $row)
    {
        if (!$Course = parent::_getCourse($Job, $row)) {
            $Course = Course::getByField('Title', $row['CourseTitle']);
        }

        return $Course;
    }
}