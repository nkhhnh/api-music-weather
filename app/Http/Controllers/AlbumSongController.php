<?php

namespace App\Http\Controllers;

use App\Models\Album;
use App\Models\UserSong;
use App\Models\AlbumSong;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class AlbumSongController extends Controller
{
    public function addSong(Request $request)
    {
        try {
            $user = auth()->user();
            if (!$user) {
                return response()->json(['message' => 'User không được xác thực'], 401);
            }
            $userId = $user->id;

            $album_id = $request->input('album_id');
            if (!$album_id) {
                return response()->json(['message' => 'album_id là bắt buộc'], 422);
            }

            $album = Album::where('user_id', $userId)->findOrFail($album_id);

            $request->validate([
                'song_id' => 'required|exists:songs,id',
                'custom_name' => 'required|string|max:255',
                'custom_artist' => 'required|string|max:255',
            ]);

            // Kiểm tra xem user đã có bài hát này trong user_songs chưa
            $existingUserSong = UserSong::where('user_id', $userId)
                ->where('song_id', $request->song_id)
                ->first();

            if ($existingUserSong) {
                // Nếu đã tồn tại, cập nhật custom_name và custom_artist
                $existingUserSong->update([
                    'custom_name' => $request->custom_name,
                    'custom_artist' => $request->custom_artist
                ]);
                $userSong = $existingUserSong;
            } else {
                // Nếu chưa tồn tại, tạo mới
                $userSong = UserSong::create([
                    'user_id' => $userId,
                    'song_id' => $request->song_id,
                    'custom_name' => $request->custom_name,
                    'custom_artist' => $request->custom_artist
                ]);
            }

            // Kiểm tra xem bài hát đã có trong album chưa
            if ($album->songs()->where('song_id', $request->song_id)->exists()) {
                return response()->json(['message' => 'Bài hát đã có trong album'], 409);
            }

            // Gắn bài hát vào album
            $album->songs()->attach($request->song_id);

            return response()->json([
                'message' => 'Thêm bài hát thành công',
                'album' => [
                    'album_id' => $album->id,
                    'album_name' => $album->album_name,
                ],
                'song' => [
                    'song_id' => $request->song_id,
                    'custom_name' => $userSong->custom_name,
                    'custom_artist' => $userSong->custom_artist,
                ]
            ], 201);
        } catch (\Exception $e) {
            Log::error('Lỗi khi thêm bài hát vào album: ' . $e->getMessage(), [
                'album_id' => $album_id ?? null,
                'song_id' => $request->song_id ?? null,
                'user_id' => $userId ?? null,
                'trace' => $e->getTraceAsString(),
            ]);
            return response()->json(['message' => 'Lỗi khi thêm bài hát: ' . $e->getMessage()], 500);
        }
    }

    public function removeSongFromAlbum($albumId, $songId)
    {
        $userId = auth()->user()->id;

        try {
            // Tìm album của user
            $album = Album::where('user_id', $userId)->findOrFail($albumId);

            // Tìm bài hát trong album
            $albumSong = AlbumSong::where('album_id', $albumId)
                ->where('song_id', $songId)
                ->first();

            if (!$albumSong) {
                return response()->json(['message' => 'Bài hát không tồn tại trong album'], 404);
            }

            // Xóa bài hát khỏi album
            $album->songs()->detach($songId);

            return response()->json(['message' => 'Song removed from album successfully']);
        } catch (\Exception $e) {
            Log::error('Lỗi khi xóa bài hát khỏi album: ' . $e->getMessage(), [
                'album_id' => $albumId,
                'song_id' => $songId,
                'user_id' => $userId,
                'trace' => $e->getTraceAsString(),
            ]);
            return response()->json(['message' => 'Lỗi khi xóa bài hát khỏi album: ' . $e->getMessage()], 500);
        }
    }

    public function downloadAllSongs(Request $request, $id)
    {
        $userId = auth()->user()->id;

        try {
            // Tìm album của user
            $album = Album::where('user_id', $userId)->findOrFail($id);

            // Lấy danh sách bài hát trong album
            $songs = $album->songs()->with(['userSongs' => function ($query) use ($userId) {
                $query->where('user_id', $userId);
            }])->get();

            if ($songs->isEmpty()) {
                return response()->json(['message' => 'Album không có bài hát nào để tải'], 404);
            }

            // Chuẩn bị danh sách thông tin bài hát
            $songData = $songs->map(function ($song) use ($userId) {
                $fileId = $this->extractFileId($song->file_path);
                $userSong = $song->userSongs->first();
                $fileName = $userSong ? $userSong->custom_name : 'song_' . $song->id . '.mp3';

                if (!Storage::disk('google')->exists($fileId)) {
                    return null; // Bỏ qua nếu file không tồn tại
                }

                return [
                    'song_id' => $song->id,
                    'custom_name' => $userSong ? $userSong->custom_name : null,
                    'custom_artist' => $userSong ? $userSong->custom_artist : null,
                    'file_name' => $fileName,
                    'file_path' => $song->file_path,
                    'url' => route('songs.download', ['id' => $song->id]),
                ];
            })->filter()->values();

            return response()->json([
                'album_id' => $album->id,
                'album_name' => $album->album_name,
                'songs' => $songData,
            ], 200);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Lỗi khi tải tất cả bài hát: ' . $e->getMessage()], 500);
        }
    }

    private function extractFileId($filePath)
    {
        $query = parse_url($filePath, PHP_URL_QUERY);
        if ($query) {
            parse_str($query, $params);
            return $params['id'] ?? basename($filePath);
        }
        return basename($filePath);
    }
}