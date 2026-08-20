<?php

namespace App\Http\Controllers;

use App\Models\Album;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class AlbumController extends Controller
{
    /**
     * Danh sach album KEM bai hat.
     *
     * Truoc day chi tra ve ban ghi album tran, khong co 'songs'. Hau qua:
     * danh sach album luon hien "Khong co bai hat trong album nay", va moi
     * lan bam vao mot album lai phai goi /albums/{id} - mot vong mang nua,
     * chinh la doan cho lau khi mo album.
     *
     * Bai hat cua TAT CA album duoc lay trong MOT truy van duy nhat, khong
     * lap query theo tung album.
     */
    public function index()
    {
        $userId = auth()->user()->id;
        $albums = Album::where('user_id', $userId)->get();

        if ($albums->isEmpty()) {
            return response()->json([], 200);
        }

        $songsByAlbum = DB::table('album_song')
            ->join('songs', 'songs.id', '=', 'album_song.song_id')
            ->leftJoin('user_song', function ($join) use ($userId) {
                $join->on('songs.id', '=', 'user_song.song_id')
                     ->where('user_song.user_id', '=', $userId);
            })
            ->whereIn('album_song.album_id', $albums->pluck('id'))
            ->select(
                'album_song.album_id',
                'songs.id as song_id',
                'songs.file_path',
                'user_song.custom_name as override_name',
                'user_song.custom_artist as override_artist'
            )
            ->orderBy('album_song.id')
            ->get()
            ->groupBy('album_id');

        $payload = $albums->map(function ($album) use ($songsByAlbum) {
            $songs = collect($songsByAlbum->get($album->id, []))
                ->map(function ($row) {
                    return [
                        'song_id'       => $row->song_id,
                        'custom_name'   => $row->override_name ?? 'Unknown',
                        'custom_artist' => $row->override_artist ?? 'Unknown',
                        'file_path'     => $row->file_path,
                    ];
                })
                ->values();

            return [
                'id'         => $album->id,
                'album_name' => $album->album_name,
                'songs'      => $songs,
                'song_count' => $songs->count(),
            ];
        });

        return response()->json($payload, 200);
    }

    public function show($id)
{
    try {
        $userId = auth()->user()->id;
        $album = Album::where('user_id', $userId)->findOrFail($id);

        $songs = $album->songs()
            ->leftJoin('user_song', function ($join) use ($userId) {
                $join->on('songs.id', '=', 'user_song.song_id')
                     ->where('user_song.user_id', '=', $userId);
            })
            ->select(
                'songs.*',
                'user_song.custom_name as override_name',
                'user_song.custom_artist as override_artist'
            )
            ->get()
            ->map(function ($song) {
                return [
                    'song_id' => $song->id,
                    'custom_name' => $song->override_name ?? ($song->name ?? 'Unknown'),
                    'custom_artist' => $song->override_artist ?? ($song->artist ?? 'Unknown'),
                    'file_path' => $song->file_path,
                ];
            });

        return response()->json([
            'id' => $album->id,
            'album_name' => $album->album_name,
            'songs' => $songs,
            'song_count' => $songs->count(),
        ], 200);
    } catch (\Exception $e) {
        Log::error('Lỗi khi lấy thông tin album: ' . $e->getMessage(), [
            'album_id' => $id,
            'user_id' => $userId,
            'trace' => $e->getTraceAsString(),
        ]);
        return response()->json(['message' => 'Lỗi khi lấy thông tin album: ' . $e->getMessage()], 500);
    }
}

    public function store(Request $request)
    {
        $userId = auth()->user()->id;
        $request->validate(['album_name' => 'required|string|max:255']);
        $album = Album::create([
            'album_name' => $request->album_name,
            'user_id' => $userId,
        ]);
        return response()->json($album, 201);
    }

    public function update(Request $request, $id)
    {
        $userId = auth()->user()->id;
        $album = Album::where('user_id', $userId)->findOrFail($id);
        $request->validate(['album_name' => 'required|string|max:255']);
        $album->update(['album_name' => $request->album_name]);
        return response()->json($album);
    }

    public function delete($id)
    {
        $userId = auth()->user()->id;
        $album = Album::where('user_id', $userId)->findOrFail($id);
        $album->delete();
        return response()->json(['message' => 'Album đã bị xóa']);
    }
}
