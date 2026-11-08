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
use App\Models\AttendanceModel;
use App\Models\DashboardModel;
use App\Models\DebtModel;
use App\Models\MembershipModel;
use App\Models\PaymentModel;
use App\Models\StudentModel;
use App\Models\UserModel;

$db = Database::connect();
$router = new Router();
$auth = new AuthController(new UserModel($db));
$students = new StudentController(new StudentModel($db));
$memberships = new MembershipController(new MembershipModel($db));
$payments = new PaymentController(new PaymentModel($db));
$debts = new DebtController(new DebtModel($db));
$attendance = new AttendanceController(new AttendanceModel($db));
$dashboard = new DashboardController(new DashboardModel($db));

$router->add('POST', '~^/login$~', [$auth, 'login'], false);
$router->add('POST', '~^/logout$~', [$auth, 'logout']);
$router->add('GET', '~^/students$~', [$students, 'index'], true, true);
$router->add('POST', '~^/students$~', [$students, 'store'], true, true);
$router->add('GET', '~^/students/(?P<id>\d+)$~', [$students, 'show'], true, true);
$router->add('PUT', '~^/students/(?P<id>\d+)$~', [$students, 'update'], true, true);
$router->add('DELETE', '~^/students/(?P<id>\d+)$~', [$students, 'destroy'], true, true);
$router->add('GET', '~^/memberships$~', [$memberships, 'index'], true, true);
$router->add('POST', '~^/memberships$~', [$memberships, 'store'], true, true);
$router->add('GET', '~^/payments$~', [$payments, 'index'], true, true);
$router->add('POST', '~^/payments$~', [$payments, 'store'], true, true);
$router->add('GET', '~^/debts$~', [$debts, 'index'], true, true);
$router->add('GET', '~^/attendance$~', [$attendance, 'index'], true, true);
$router->add('POST', '~^/attendance$~', [$attendance, 'store'], true, true);
$router->add('GET', '~^/dashboard$~', [$dashboard, 'summary'], true, true);

$route = $_GET['route'] ?? '/';
if (!is_string($route) || !str_starts_with($route, '/') || str_contains($route, '..')) {
    http_response_code(400);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'Ruta inválida.'], JSON_UNESCAPED_UNICODE);
    return;
}
$router->dispatch($_SERVER['REQUEST_METHOD'] ?? 'GET', $route);
