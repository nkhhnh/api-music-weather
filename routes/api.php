<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
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
Route::get('/api/test', function () {
    return response()->json([
        'message' => 'API is working!',
        'status' => 'success',
        'timestamp' => now()
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