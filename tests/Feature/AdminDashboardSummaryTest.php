<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Project;
use App\Models\Student;
use App\Models\Submission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminDashboardSummaryTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_summary_returns_aggregates_and_small_recent_lists(): void
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
        $publishedCourse = Course::create([
            'title' => 'Published course',
            'status' => 'published',
        ]);
        Course::create([
            'title' => 'Draft course',
            'status' => 'draft',
        ]);
        $project = Project::create([
            'course_id' => $publishedCourse->id,
            'title' => 'Project',
        ]);
        Submission::create([
            'student_id' => $student->id,
            'project_id' => $project->id,
            'github_link' => 'https://github.com/example/project',
            'status' => 'under_review',
        ]);

        Sanctum::actingAs($admin);

        $this->getJson('/api/admin/dashboard')
            ->assertOk()
            ->assertJsonPath('stats.totalStudents', 1)
            ->assertJsonPath('stats.totalCourses', 2)
            ->assertJsonPath('stats.publishedCourses', 1)
            ->assertJsonPath('stats.totalProjects', 1)
            ->assertJsonPath('stats.pendingSubmissions', 1)
            ->assertJsonPath('recentStudents.0.user.email', 'student@example.com')
            ->assertJsonPath('recentSubmissions.0.student.user.email', 'student@example.com');
    }
}
