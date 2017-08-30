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
    // AbstractConnector overrides
    public static $title = 'Infinite Campus';
    public static $connectorId = 'infinite-campus';
    
    public static $studentColumns = [
        'Student Number' => 'StudentNumber',
        'Homeroom Teacher' => 'AdvisorFullName'
    ];
    
    public static $sectionColumns = [
        'Section ID' => 'SectionExternal',
        'Course Number' => 'CourseExternal',
        'Course Name' => 'CourseTitle',
        'Max Students' => 'StudentsCapacity',
#        'Teacher Display' => 'TeacherFullName',
        'Room Name' => 'Location'
    ];
    
    public static $sectionRequiredColumns = [];
    
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
                $pretend,
                SpreadsheetReader::createFromStream(fopen($Job->Config['studentsCsv'], 'r'))
            );
        }

        if (!empty($Job->Config['sectionsCsv'])) {
            $results['pull-sections'] = static::pullSections(
                $Job,
                $pretend,
                SpreadsheetReader::createFromStream(fopen($Job->Config['sectionsCsv'], 'r'))
            );
        }

        if (!empty($Job->Config['enrollmentsCsv'])) {
            $results['pull-enrollments'] = static::pullEnrollments(
                $Job,
                $pretend,
                SpreadsheetReader::createFromStream(fopen($Job->Config['enrollmentsCsv'], 'r'))
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
    
    public static function pullSection(Job $Job, array $row, Term $MasterTerm, array &$results, $pretend = true)
    {
        $Record = null;
        $Mapping = null;

        // process input row through column mapping
        $row = static::_readSection($Job, $row);


        // start logging analysis
        $results['analyzed']++;
        static::_logRow($Job, 'sections', $results['analyzed'], $row);


        // skip row if filter function flags it
        if ($filterReason = static::_filterSection($Job, $row)) {
            $results['filtered'][$filterReason]++;
            $Job->notice('Skipping section row #{rowNumber} due to filter: {reason}', [
                'rowNumber' => $results['analyzed'],
                'reason' => $filterReason
            ]);
            return;
        }

        if (empty($row['SectionExternal']) && empty($row['SectionCode'])) {
            $results['failed']['missing-required-field']['SectionCode']++;
            $Job->error('Missing section code for row #{rowNumber}', ['rowNumber' => $results['analyzed']]);
            return;
        }

        if (!$Record = static::_getSection($Job, $row, $MasterTerm)) {
            // create new section
            $Record = Section::create();

            if (!empty($row['SectionCode'])) {
                $Record->Code = $row['SectionCode'];
            }
        }

        // get teacher, but add later
        $Teacher = null;
        if (!$Teacher = static::_getTeacher($Job, $row)) {
            if (!empty($row['TeacherUsername'])) {
                $results['failed']['teacher-not-found-by-username'][$row['TeacherUsername']]++;
            } elseif (isset($row['TeacherFullName']) || (isset($row['TeacherFirstName']) && isset($row['TeacherLastName']))) {
                if (isset($row['TeacherFullName'])) {
                    $fullName = $row['TeacherFullName'];
                } else {
                    $fullName = $row['TeacherFirstName'] . ' ' . $row['TeacherLastName'];
                }
                $results['failed']['teacher-not-found-by-name'][$fullName]++;
            }
            return;
        }

        // get or create course
        if (!$Course = static::_getCourse($Job, $row)) {
            if (!empty($row['CourseTitle'])) {
                $results['failed']['course-not-found-by-title'][$row['CourseTitle']]++;
            } elseif (!empty($row['CourseExternal'])) {
                $results['failed']['course-not-found-by-external-identifier'][$row['CourseExternal']]++;
            } elseif (!empty($row['CourseCode'])) {
                $results['failed']['course-not-found-by-code'][$row['CourseCode']]++;
            }
            return;
        }

        $Record->Course = $Course;


        // apply values from spreadsheet
        try {
            static::_applySectionChanges($Job, $MasterTerm, $Record, $row, $results);
        } catch (RemoteRecordInvalid $e) {
            if ($e->getValueKey()) {
                $results['failed'][$e->getMessageKey()][$e->getValueKey()]++;
            } else {
                $results['failed'][$e->getMessageKey()]++;
            }

            $Job->logException($e);
            return;
        }


        // validate record
        if (!$Record->validate()) {
            $firstErrorField = key($Record->validationErrors);
            $error = $Record->validationErrors[$firstErrorField];
            $results['failed']['invalid'][$firstErrorField][is_array($error) ? http_build_query($error) : $error]++;
            $Job->logInvalidRecord($Record);
            return;
        }


        // log changes
        $logEntry = $Job->logRecordDelta($Record);

        if ($logEntry['action'] == 'create') {
            $results['created']++;
        } elseif ($logEntry['action'] == 'update') {
            $results['updated']++;
        } else {
            $results['unmodified']++;
        }


        // log related changes
        if ($Record->Course) {
            $Job->logRecordDelta($Record->Course);
        }

        if ($Record->Course->Department) {
            $Job->logRecordDelta($Record->Course->Department);
        }

         if ($Record->Term) {
            $Job->logRecordDelta($Record->Term);
        }

        if ($Record->Schedule) {
            $Job->logRecordDelta($Record->Schedule);
        }

        if ($Record->Location) {
            $Job->logRecordDelta($Record->Location);
        }


        // save changes
        if (!$pretend) {
            $Record->save();
        }


        // save mapping
        if (($externalIdentifier = static::_getSectionExternalIdentifier($row, $MasterTerm)) && !($Mapping = static::_getSectionMapping($externalIdentifier))) {
            $Mapping = Mapping::create([
                'Context' => $Record
                ,'Source' => 'creation'
                ,'Connector' => static::getConnectorId()
                ,'ExternalKey' => static::$sectionForeignKeyName
                ,'ExternalIdentifier' => $externalIdentifier
            ], !$pretend);

            $Job->notice('Mapping external identifier {externalIdentifier} to section {sectionTitle}', [
                'externalIdentifier' => $externalIdentifier,
                'sectionTitle' => $Record->getTitle()
            ]);
        }


        // add teacher
        if ($Teacher) {
            $Participant = static::_getOrCreateParticipant($Record, $Teacher, 'Teacher', $pretend);
            $logEntry = static::_logParticipant($Job, $Participant);

            if ($logEntry['action'] == 'create') {
                $results['teacher-enrollments-created']++;
            } elseif ($logEntry['action'] == 'update') {
                $results['teacher-enrollments-updated']++;
            }
        }
        
        // add secondary teacher
        if (!empty($row['_rest']['Teacher 2 Display']) && preg_match("/^([a-z\'\-]+),\s([a-z\'\-]+)/i", $row['_rest']['Teacher 2 Display'], $matches)) {
            if (!$Teacher = User::getByFullName($matches[2], $matches[1])) {
                $Job->error('Teacher not found for full name {name}', ['name' => $matches[2] . ' ' . $matches[1]]);
                return;
            }
            
            $Participant = static::_getOrCreateParticipant($Section, $Teacher, 'Teacher', $pretend);
            $logEntry = static::_logParticipant($Job, $Participant);

            if ($logEntry['action'] == 'create') {
                $results['teacher-enrollments-created']++;
            } elseif ($logEntry['action'] == 'update') {
                $results['teacher-enrollments-updated']++;
            }
        }
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
    
    protected static function _readSection($Job, array $row)
    {
        $row = static::_readRow($row, static::$sectionColumns);
        
        $teacherDisplay = (
            isset($row['Teacher Display']) ?
                $row['Teacher Display'] :
                (
                    isset($row['_rest']['Teacher Display']) ?
                        $row['_rest']['Teacher Display'] :
                        null
                )
        );

        if ($teacherDisplay && preg_match("/^([a-z\-\']+),\s([a-z\-\']+)/i", $teacherDisplay, $matches)) {
            $row['TeacherFullName'] = $matches[2] . ' ' . $matches[1];
        }

        static::_fireEvent('readSection', [
            'Job' => $Job,
            'row' => &$row
        ]);

        return $row;
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