<?php
declare(strict_types=1);

use App\Config\Database;
use App\Controllers\AttendanceController;
use App\Controllers\AuthController;
use App\Controllers\DashboardController;
use App\Controllers\DebtController;
use App\Controllers\MembershipController;
use App\Controllers\PaymentController;
use App\Controllers\StudentController;
use App\Core\Router;
use App\Models\AdminModel;
use App\Models\AttendanceModel;
use App\Models\DashboardModel;
use App\Models\DebtModel;
use App\Models\MembershipModel;
use App\Models\PaymentModel;
use App\Models\StudentModel;

$db = Database::connect();
$router = new Router();
$auth = new AuthController(new AdminModel($db));
$students = new StudentController(new StudentModel($db));
$memberships = new MembershipController(new MembershipModel($db));
$payments = new PaymentController(new PaymentModel($db));
$debts = new DebtController(new DebtModel($db));
$attendance = new AttendanceController(new AttendanceModel($db));
$dashboard = new DashboardController(new DashboardModel($db));

$router->add('POST', '~^/login$~', [$auth, 'login'], false);
$router->add('POST', '~^/logout$~', [$auth, 'logout']);
$router->add('GET', '~^/students$~', [$students, 'index']);
$router->add('POST', '~^/students$~', [$students, 'store']);
$router->add('GET', '~^/students/(?P<id>\d+)$~', [$students, 'show']);
$router->add('PUT', '~^/students/(?P<id>\d+)$~', [$students, 'update']);
$router->add('DELETE', '~^/students/(?P<id>\d+)$~', [$students, 'destroy']);
$router->add('GET', '~^/memberships$~', [$memberships, 'index']);
$router->add('POST', '~^/memberships$~', [$memberships, 'store']);
$router->add('GET', '~^/payments$~', [$payments, 'index']);
$router->add('POST', '~^/payments$~', [$payments, 'store']);
$router->add('GET', '~^/debts$~', [$debts, 'index']);
$router->add('GET', '~^/attendance$~', [$attendance, 'index']);
$router->add('POST', '~^/attendance$~', [$attendance, 'store']);
$router->add('GET', '~^/dashboard$~', [$dashboard, 'summary']);

$route = $_GET['route'] ?? '/';
if (!is_string($route) || !str_starts_with($route, '/') || str_contains($route, '..')) {
    http_response_code(400);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'Ruta inválida.'], JSON_UNESCAPED_UNICODE);
    return;
}
$router->dispatch($_SERVER['REQUEST_METHOD'] ?? 'GET', $route);
