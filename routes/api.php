<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\DB;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\SongController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\AlbumController;
use App\Http\Controllers\AlbumSongController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/
Route::get('/test', function () {
    return response()->json([
        'message' => 'API is working!',
        'status' => 'success',
        'timestamp' => now()
    ], 200);
});

// ── TAM THOI: do do tre cua DB. Xoa sau khi co ket qua. ─────────────────
// Tach bach hai thu hoan toan khac nhau:
//   - mo ket noi ton bao lau (mot lan moi request)
//   - moi truy van sau do ton bao lau (do tre mang tren tung vong)
// Mo ket noi 2s + truy van 5ms  -> ket noi ben giai quyet duoc.
// Moi truy van deu 400ms        -> do tre mang, phai dua DB va app ve cung vung.
Route::get('/dbcheck', function () {
    $ms = fn ($t) => round((microtime(true) - $t) * 1000, 1);

    // Buoc 1: mo ket noi. Laravel ket noi lazy nen getPdo() moi that su mo.
    $t = microtime(true);
    try {
        DB::connection()->getPdo();
    } catch (\Throwable $e) {
        return response()->json([
            'error' => 'Khong ket noi duoc DB',
            'chi_tiet' => $e->getMessage(),
            'mo_ket_noi_ms' => $ms($t),
        ], 500);
    }
    $connectMs = $ms($t);

    // Buoc 2: truy van re nhat co the, tren ket noi da mo san.
    $t = microtime(true);
    DB::select('SELECT 1');
    $firstMs = $ms($t);

    // Buoc 3: lap lai 5 lan de thay chi phi tung vong co on dinh khong.
    $each = [];
    for ($i = 0; $i < 5; $i++) {
        $t = microtime(true);
        DB::select('SELECT 1');
        $each[] = $ms($t);
    }

    // Buoc 4: mot truy van that co cham bang, de so voi SELECT 1.
    $t = microtime(true);
    try {
        DB::table('users')->count();
        $countMs = $ms($t);
    } catch (\Throwable $e) {
        $countMs = 'loi: ' . $e->getMessage();
    }

    return response()->json([
        'mo_ket_noi_ms'      => $connectMs,
        'select_1_lan_dau_ms' => $firstMs,
        'select_1_lap_lai_ms' => $each,
        'dem_bang_users_ms'   => $countMs,
        'tong_5_lan_ms'       => round(array_sum($each), 1),
    ], 200);
});



Route::post('/register', [AuthController::class, 'register']);

Route::post('/login', [AuthController::class, 'login']); 

Route::post('/users/forgotpassword', [UserController::class, 'forgotPassword']);

Route::get('/songs/{id}/stream', [SongController::class, 'stream']);

Route::get('/songs/{id}/download', [SongController::class, 'download']);

Route::middleware('auth:sanctum','throttle:60,1')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
});



Route::prefix('users')->middleware('auth:sanctum','throttle:60,1')->group(function () {
    Route::get('/', [UserController::class, 'getUser']);      
    Route::put('/', [UserController::class, 'update']); // Cập nhật thông tin & đổi mật khẩu
    Route::delete('/{id}', [UserController::class, 'destroy']); // Xóa người dùng
});

Route::middleware('auth:sanctum','throttle:60,1')->group(function () {
    // Song routes
    Route::get('/songs', [SongController::class, 'index']);
    Route::post('/songs', [SongController::class, 'store']);
    // Hoi truoc bang ma hash: file da co san thi khong phai tai len byte nao
    Route::post('/songs/check-hash', [SongController::class, 'checkHash']);
    Route::delete('/songs/{id}', [SongController::class, 'destroy']);

    // Album routes
    Route::get('/albums', [AlbumController::class, 'index']);
    Route::get('/albums/{id}', [AlbumController::class, 'show']);
    Route::post('/albums', [AlbumController::class, 'store']);
    Route::put('/albums/{id}', [AlbumController::class, 'update']);
    Route::delete('/albums/{id}', [AlbumController::class, 'delete']);
    Route::get('/albums/{id}/download-all', [AlbumController::class, 'downloadAllSongs']); // Đúng controller

    // AlbumSong routes
    Route::post('/album-song', [AlbumSongController::class, 'addSong']); 
    Route::delete('/album-song/{album_id}/{song_id}', [AlbumSongController::class, 'removeSongFromAlbum']);
});
