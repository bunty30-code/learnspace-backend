<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Models\Project;
use App\Models\Student;
use App\Models\Submission;

class DashboardController extends Controller
{
    public function index()
    {
        $courseCounts = Course::query()
            ->selectRaw('COUNT(*) as total')
            ->selectRaw("COALESCE(SUM(CASE WHEN status = 'published' THEN 1 ELSE 0 END), 0) as published")
            ->first();

        $pendingSubmissions = Submission::whereIn('status', ['pending', 'under_review'])
            ->count();

        return response()->json([
            'stats' => [
                'totalStudents' => Student::count(),
                'totalCourses' => (int) $courseCounts->total,
                'publishedCourses' => (int) $courseCounts->published,
                'totalProjects' => Project::count(),
                'pendingSubmissions' => $pendingSubmissions,
            ],
            'recentStudents' => Student::with('user')
                ->orderBy('id')
                ->limit(5)
                ->get(),
            'recentSubmissions' => Submission::with(['student.user', 'project'])
                ->orderBy('id')
                ->limit(5)
                ->get(),
        ]);
    }
}
