<?php

namespace App\Http\Controllers;

use App\Models\Song;
use App\Models\UserSong;
use App\Models\AlbumSong;
use Google\Service\Drive;
use Illuminate\Http\Request;
use Google\Service\Drive\DriveFile;
use Illuminate\Support\Facades\Log;
use Google\Service\Drive\Permission;

class SongController extends Controller
{
    protected $drive;

    public function __construct(Drive $drive)
    {
        $this->drive = $drive;
    }

    public function index()
    {
        $userId = auth()->user()->id;

        $songs = UserSong::where('user_id', $userId)
            ->with('song')
            ->get()
            ->map(function ($userSong) {
                return [
                    'song_id' => $userSong->song->id,
                    'custom_name' => $userSong->custom_name,
                    'custom_artist' => $userSong->custom_artist,
                    'file_path' => $userSong->song->file_path,
                ];
            });

        return response()->json($songs->isEmpty() ? [] : $songs, 200);
    }

    public function store(Request $request)
    {
        $request->validate([
            'file' => 'required|file|mimes:mp3,mpeg|max:20480',
            'custom_name' => 'required|string|max:255',
            'custom_artist' => 'required|string|max:255',
        ]);

        $userId = auth()->user()->id;
        $file = $request->file('file');

        $extension = strtolower($file->getClientOriginalExtension());
        if (!in_array($extension, ['mp3', 'mpeg'])) {
            return response()->json(['message' => 'File must be a valid MP3 or MPEG!'], 422);
        }

        if (!$file->isValid()) {
            return response()->json(['message' => 'File không hợp lệ hoặc bị lỗi khi upload!'], 422);
        }

        try {
            $originalName = $file->getClientOriginalName();
            $fileName = time() . '_' . str_replace(' ', '_', $originalName);

            $driveFile = new DriveFile();
            $driveFile->setName($fileName);
            $driveFile->setParents([config('services.google_drive.folder_id')]);

            $fileContent = file_get_contents($file->getRealPath());
            $fileHash = hash('sha256', $fileContent);

            // Kiểm tra xem file đã tồn tại trên Google Drive hay chưa (dựa trên file_hash)
            $existingSong = Song::where('file_hash', $fileHash)->first();
            if ($existingSong) {
                $song = $existingSong;
                $filePath = $song->file_path;
            } else {
                // Tải file lên Google Drive và tạo bản ghi mới trong bảng songs
                $uploadedFile = $this->drive->files->create($driveFile, [
                    'data' => $fileContent,
                    'mimeType' => $file->getClientMimeType(),
                    'uploadType' => 'multipart',
                ]);

                $fileId = $uploadedFile->id;
                if (!$fileId) {
                    throw new \Exception('Không thể trích xuất file ID sau khi upload');
                }

                $permission = new Permission([
                    'type' => 'anyone',
                    'role' => 'reader',
                ]);
                $this->drive->permissions->create($fileId, $permission);

                $filePath = "https://drive.google.com/uc?export=download&id={$fileId}";
                $song = Song::create([
                    'file_path' => $filePath,
                    'file_hash' => $fileHash,
                ]);
            }

            // Kiểm tra xem user đã có bài hát này trong danh sách hay chưa
            $userSong = UserSong::where('user_id', $userId)
                ->where('song_id', $song->id)
                ->first();

            if ($userSong) {
                // Nếu đã tồn tại, cập nhật custom_name và custom_artist
                $userSong->update([
                    'custom_name' => $request->custom_name,
                    'custom_artist' => $request->custom_artist,
                ]);
            } else {
                // Nếu chưa tồn tại, tạo mới
                $userSong = $song->userSongs()->create([
                    'user_id' => $userId,
                    'custom_name' => $request->custom_name,
                    'custom_artist' => $request->custom_artist,
                ]);
            }

            return response()->json([
                'message' => 'Upload bài hát thành công',
                'song' => [
                    'song_id' => $song->id,
                    'custom_name' => $userSong->custom_name,
                    'custom_artist' => $userSong->custom_artist,
                    'file_path' => $filePath,
                ]
            ], 201);
        } catch (\Exception $e) {
            if (isset($fileId)) {
                try {
                    $this->drive->files->delete($fileId);
                } catch (\Exception $deleteException) {
                    Log::error('Lỗi khi xóa file trên Google Drive: ' . $deleteException->getMessage());
                }
            }

            return response()->json([
                'message' => 'Lỗi khi upload bài hát: ' . $e->getMessage()
            ], 500);
        }
    }

    public function destroy(Request $request, $id)
    {
        $userId = auth()->user()->id;

        try {
            // Tìm bài hát trong bảng songs
            $song = Song::whereHas('userSongs', function ($query) use ($userId) {
                $query->where('user_id', $userId);
            })->findOrFail($id);

            // Tìm và xóa bản ghi trong user_songs
            $userSong = UserSong::where('user_id', $userId)
                ->where('song_id', $song->id)
                ->first();

            if (!$userSong) {
                return response()->json(['message' => 'Bài hát không tồn tại trong danh sách của bạn'], 404);
            }

            // Xóa bản ghi
            $userSong->delete();

            // Xóa bài hát khỏi tất cả album của user
            AlbumSong::where('song_id', $song->id)
                ->whereHas('album', function ($query) use ($userId) {
                    $query->where('user_id', $userId);
                })
                ->delete();

            // Kiểm tra lại số lượng user còn sử dụng bài hát này
            $remainingUsers = UserSong::where('song_id', $song->id)->count();

            Log::info('Remaining users for song', [
                'song_id' => $song->id,
                'remaining_users' => $remainingUsers,
            ]);

            if ($remainingUsers === 0) {
                // Nếu không còn user nào sử dụng, xóa file trên Google Drive và bản ghi trong songs
                $fileId = $this->extractFileId($song->file_path);
                Log::info('Extracted file ID', [
                    'file_path' => $song->file_path,
                    'file_id' => $fileId,
                ]);

                if ($fileId) {
                    try {
                        $this->drive->files->delete($fileId);
                        Log::info('Deleted file from Google Drive', ['file_id' => $fileId]);
                    } catch (\Exception $e) {
                        Log::error('Lỗi khi xóa file trên Google Drive: ' . $e->getMessage(), [
                            'file_id' => $fileId,
                            'trace' => $e->getTraceAsString(),
                        ]);
                    }
                } else {
                    Log::warning('Không thể trích xuất file_id từ file_path', [
                        'file_path' => $song->file_path,
                    ]);
                }
                $song->delete();
            }

            return response()->json(['message' => 'Xóa bài hát thành công'], 200);
        } catch (\Exception $e) {
            Log::error('Lỗi khi xóa bài hát: ' . $e->getMessage(), [
                'song_id' => $id,
                'user_id' => $userId,
                'trace' => $e->getTraceAsString(),
            ]);
            return response()->json(['message' => 'Lỗi khi xóa bài hát: ' . $e->getMessage()], 500);
        }
    }

    public function stream(Request $request, $id)
    {
        try {
            // Tìm bài hát trong database
            $song = Song::findOrFail($id);
            $fileId = $this->extractFileId($song->file_path);

            if (!$fileId) {
                throw new \Exception('Không thể trích xuất file ID từ đường dẫn');
            }

            // Refresh access token nếu cần
            $client = $this->drive->getClient();
            if ($client->isAccessTokenExpired()) {
                $client->fetchAccessTokenWithRefreshToken($client->getRefreshToken());
                if (!$client->getAccessToken()) {
                    throw new \Exception('Không thể làm mới access token cho Google Drive API');
                }
            }

            // Lấy thông tin file từ Google Drive
            try {
                $file = $this->drive->files->get($fileId, ['fields' => 'size,mimeType']);
            } catch (\Google\Service\Exception $e) {
                throw new \Exception('Lỗi khi lấy thông tin file từ Google Drive: ' . $e->getMessage());
            }

            $fileSize = $file->size;

            // Kiểm tra mimeType từ Google Drive
            $mimeType = $file->mimeType;
            if ($mimeType !== 'audio/mpeg') {
                throw new \Exception('File không phải định dạng MP3: ' . $mimeType);
            }

            // Mặc định trả về toàn bộ file
            $start = 0;
            $end = $fileSize - 1;
            $length = $fileSize;
            $statusCode = 200;

            // Xử lý yêu cầu Range (nếu có)
            if ($range = $request->header('Range')) {
                [$start, $end] = sscanf(str_replace('bytes=', '', $range), "%d-%d");
                $end = $end ?: $fileSize - 1;
                $length = $end - $start + 1;
                $statusCode = 206;
            }

            // Tạo yêu cầu đến Google Drive với header Range
            $httpClient = $client->getHttpClient();
            $request = new \GuzzleHttp\Psr7\Request(
                'GET',
                "https://www.googleapis.com/drive/v3/files/{$fileId}?alt=media",
                [
                    'Authorization' => 'Bearer ' . $client->getAccessToken()['access_token'],
                    'Range' => "bytes={$start}-{$end}",
                ]
            );

            // Gửi yêu cầu và lấy phản hồi từ Google Drive
            try {
                $response = $httpClient->send($request);
            } catch (\GuzzleHttp\Exception\RequestException $e) {
                throw new \Exception('Lỗi khi gửi yêu cầu đến Google Drive: ' . $e->getMessage());
            }

            $status = $response->getStatusCode();

            // Kiểm tra trạng thái phản hồi từ Google Drive
            if ($status !== 200 && $status !== 206) {
                throw new \Exception('Google Drive API trả về lỗi: ' . $response->getReasonPhrase() . ' (Status: ' . $status . ')');
            }

            // Kiểm tra Content-Type từ Google Drive
            $contentType = $response->getHeaderLine('Content-Type');
            if (strpos($contentType, 'audio/mpeg') === false) {
                throw new \Exception('File không phải định dạng MP3: ' . $contentType);
            }

            // Lấy stream từ phản hồi
            $stream = $response->getBody()->detach();

            // Kiểm tra nếu stream không hợp lệ
            if (!$stream || !is_resource($stream)) {
                throw new \Exception('Không thể lấy stream từ Google Drive');
            }

            // Tạo phản hồi stream bằng response()->stream()
            $headers = [
                'Content-Type' => 'audio/mpeg',
                'Content-Length' => $length,
                'Accept-Ranges' => 'bytes',
            ];

            if ($statusCode === 206) {
                $headers['Content-Range'] = "bytes {$start}-{$end}/{$fileSize}";
            }

            return response()->stream(
                function () use ($stream) {
                    // Đọc dữ liệu từ stream và gửi đến client
                    while (!feof($stream)) {
                        echo fread($stream, 16384);
                        flush();
                    }
                    // Đóng stream sau khi hoàn tất
                    if (is_resource($stream)) {
                        fclose($stream);
                    }
                },
                $statusCode,
                $headers
            );
        } catch (\Exception $e) {
            // Đóng stream nếu có lỗi
            if (isset($stream) && is_resource($stream)) {
                fclose($stream);
            }
            // Ghi log lỗi để debug
            Log::error('Lỗi khi stream file: ' . $e->getMessage(), [
                'song_id' => $id,
                'file_id' => $fileId ?? null,
                'trace' => $e->getTraceAsString(),
            ]);
            return response()->json(['message' => 'Lỗi khi stream file: ' . $e->getMessage()], 500);
        }
    }

    public function download(Request $request, $id)
{
    try {
        // Không yêu cầu xác thực, chỉ cần tìm bài hát trong database
        $song = Song::findOrFail($id);

        $fileId = $this->extractFileId($song->file_path);
        if (!$fileId) {
            throw new \Exception('Không thể trích xuất file ID từ đường dẫn');
        }

        // Refresh access token nếu cần
        $client = $this->drive->getClient();
        if ($client->isAccessTokenExpired()) {
            $client->fetchAccessTokenWithRefreshToken($client->getRefreshToken());
            if (!$client->getAccessToken()) {
                throw new \Exception('Không thể làm mới access token cho Google Drive API');
            }
        }

        // Lấy thông tin file từ Google Drive
        try {
            $file = $this->drive->files->get($fileId, ['fields' => 'size,mimeType']);
        } catch (\Google\Service\Exception $e) {
            throw new \Exception('Lỗi khi lấy thông tin file từ Google Drive: ' . $e->getMessage());
        }

        $fileSize = $file->size;

        // Kiểm tra mimeType từ Google Drive
        $mimeType = $file->mimeType;
        if ($mimeType !== 'audio/mpeg') {
            throw new \Exception('File không phải định dạng MP3: ' . $mimeType);
        }

        // Tạo yêu cầu đến Google Drive
        $httpClient = $client->getHttpClient();
        $request = new \GuzzleHttp\Psr7\Request(
            'GET',
            "https://www.googleapis.com/drive/v3/files/{$fileId}?alt=media",
            [
                'Authorization' => 'Bearer ' . $client->getAccessToken()['access_token'],
            ]
        );

        // Gửi yêu cầu và lấy phản hồi từ Google Drive
        try {
            $response = $httpClient->send($request);
        } catch (\GuzzleHttp\Exception\RequestException $e) {
            throw new \Exception('Lỗi khi gửi yêu cầu đến Google Drive: ' . $e->getMessage());
        }

        $status = $response->getStatusCode();

        // Kiểm tra trạng thái phản hồi từ Google Drive
        if ($status !== 200) {
            throw new \Exception('Google Drive API trả về lỗi: ' . $response->getReasonPhrase() . ' (Status: ' . $status . ')');
        }

        // Kiểm tra Content-Type từ Google Drive
        $contentType = $response->getHeaderLine('Content-Type');
        if (strpos($contentType, 'audio/mpeg') === false) {
            throw new \Exception('File không phải định dạng MP3: ' . $contentType);
        }

        // Lấy stream từ phản hồi
        $stream = $response->getBody()->detach();

        // Kiểm tra nếu stream không hợp lệ
        if (!$stream || !is_resource($stream)) {
            throw new \Exception('Không thể lấy stream từ Google Drive');
        }

        $customName = $song->custom_name ?? 'song_' . $song->id;
        $fileName = $this->sanitizeFileName($customName) . '.mp3';

        // Tạo phản hồi stream cho download
        return response()->stream(
            function () use ($stream) {
                while (!feof($stream)) {
                    echo fread($stream, 8192);
                    flush();
                }
                if (is_resource($stream)) {
                    fclose($stream);
                }
            },
            200,
            [
                'Content-Type' => 'audio/mpeg',
                'Content-Length' => $fileSize,
                'Content-Disposition' => 'attachment; filename="' . $fileName . '"',
            ]
        );
    } catch (\Exception $e) {
        if (isset($stream) && is_resource($stream)) {
            fclose($stream);
        }
        Log::error('Lỗi khi tải file: ' . $e->getMessage(), [
            'song_id' => $id,
            'file_id' => $fileId ?? null,
            'trace' => $e->getTraceAsString(),
        ]);
        return response()->json(['message' => 'Lỗi khi tải file: ' . $e->getMessage()], 500);
    }
}

    private function extractFileId($filePath)
    {
        if (empty($filePath)) {
            return null;
        }

        $query = parse_url($filePath, PHP_URL_QUERY);
        if ($query) {
            parse_str($query, $params);
            return $params['id'] ?? null;
        }

        return null;
    }

    private function sanitizeFileName($name)
    {
        $name = preg_replace('/[^A-Za-z0-9\-_\s]/', '', $name);
        $name = substr($name, 0, 100);
        return trim($name);
    }
}