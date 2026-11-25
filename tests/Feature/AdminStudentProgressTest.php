<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Project;
use App\Models\Student;
use App\Models\Submission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminStudentProgressTest extends TestCase
{
    use RefreshDatabase;

    public function test_student_list_keeps_progress_summary_and_course_breakdown(): void
    {
        $admin = User::create([
            'name' => 'Admin',
            'email' => 'admin@example.com',
            'password' => Hash::make('secret1234'),
            'role' => 'admin',
        ]);
        $studentUser = User::create([
            'name' => 'Student',
            'email' => 'student@example.com',
            'password' => Hash::make('secret1234'),
            'role' => 'student',
        ]);
        $student = Student::create(['user_id' => $studentUser->id]);
        $course = Course::create(['title' => 'Course']);
        $project = Project::create([
            'course_id' => $course->id,
            'title' => 'Project',
        ]);
        Enrollment::create([
            'student_id' => $student->id,
            'course_id' => $course->id,
            'progress' => 40,
            'lessons_completed' => 1,
        ]);
        Submission::create([
            'student_id' => $student->id,
            'project_id' => $project->id,
            'github_link' => 'https://github.com/example/project',
            'status' => 'pending',
        ]);

        Sanctum::actingAs($admin);

        $this->getJson('/api/admin/students')
            ->assertOk()
            ->assertJsonPath('students.0.progress.enrolled', 1)
            ->assertJsonPath('students.0.progress.pending_submissions', 1)
            ->assertJsonPath('students.0.progress.courses.0.course_id', $course->id)
            ->assertJsonPath('students.0.progress.courses.0.project', 'in_review');
    }
}
