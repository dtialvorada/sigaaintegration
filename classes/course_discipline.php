<?php
namespace local_sigaaintegration;
class course_discipline
{
    public $course_id;
    public $course_code;
    public $course_name;
    public $course_level;
    public $status;
    public $discipline_name;
    public $discipline_code;
    public $discipline_id;
    public $semester_offered;
    public $period;
    public $enrollment_status;
    public $class_group;
    public $education_mode;
    public $shift;

    // Construtor da classe
    public function __construct($course_id, $course_code, $course_name, $course_level, $status,
                                $discipline_name, $discipline_code, $discipline_id,
                                $semester_offered, $period,
                                $enrollment_status, $class_group, $education_mode, $shift)
    {
        $this->course_id = $course_id;
        $this->course_code = $course_code;
        $this->course_name = $course_name;
        $this->course_level = $course_level;
        $this->status = $status;
        $this->discipline_name = $discipline_name;
        $this->discipline_code = $discipline_code;
        $this->discipline_id = $discipline_id;
        $this->semester_offered = $semester_offered;
        $this->period = $period;
        $this->enrollment_status = $enrollment_status;
        $this->class_group = $class_group;
        $this->education_mode = $education_mode;
        $this->shift = $shift;
    }

    public function isEqual(course_discipline $other) {
        return $this->course_id === $other->course_id &&
            $this->course_code === $other->course_code &&
            $this->course_name === $other->course_name &&
            $this->course_level === $other->course_level &&
            $this->status === $other->status &&
            $this->discipline_name === $other->discipline_name &&
            $this->discipline_code === $other->discipline_code &&
            $this->discipline_id === $other->discipline_id &&
            $this->semester_offered === $other->semester_offered &&
            $this->period === $other->period &&
            $this->enrollment_status === $other->enrollment_status &&
            $this->class_group === $other->class_group &&
            $this->education_mode === $other->education_mode;
    }

    public function generate_course_idnumber(campus $campus) {
        $class_group = str_replace(' ', '', $this->class_group);
        return "{$campus->id_campus}.{$this->course_id}.{$this->discipline_id}.{$class_group}.{$this->period}.{$this->semester_offered}";
    }

}