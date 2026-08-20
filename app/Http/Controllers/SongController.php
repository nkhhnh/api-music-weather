<?php

namespace App\Http\Controllers;

use App\Models\Song;
use App\Models\UserSong;
use App\Models\AlbumSong;
use Google\Service\Drive;
use Google\Http\MediaFileUpload;
use Illuminate\Http\Request;
use Google\Service\Drive\DriveFile;
use Illuminate\Support\Facades\Log;
use Google\Service\Drive\Permission;
use Illuminate\Support\Facades\Storage;
class SongController extends Controller
{
    protected $drive;

    const CHUNK_SIZE = 1572864;

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

        // --- Biến theo dõi để rollback thủ công ---
        $fileId    = null; // Drive file ID nếu đã upload thành công
        $newSongId = null; // Song ID nếu đã tạo mới trong DB (không phải bài có sẵn)

        try {
            // hash_file() doc theo luong tu dia. Truoc day file_get_contents()
            // nap nguyen file vao RAM roi hash tren bien do, va chinh bien do
            // lai duoc truyen cho Drive -> file nam trong bo nho it nhat hai
            // ban, trong khi memory_limit chi co 256M.
            $filePathOnDisk = $file->getRealPath();
            $fileHash       = hash_file('sha256', $filePathOnDisk);

            if ($fileHash === false) {
                throw new \Exception('Khong the tinh ma hash cua file');
            }

            // Kiểm tra file đã tồn tại trên Drive hay chưa (theo hash)
            $existingSong = Song::where('file_hash', $fileHash)->first();

            if ($existingSong) {
                // Bài hát đã có sẵn → dùng lại, không upload Drive, không tạo Song mới
                $song     = $existingSong;
                $filePath = $song->file_path;
            } else {
                // ── BƯỚC 1: Upload lên Google Drive ──────────────────────────
                $originalName = $file->getClientOriginalName();
                $fileName     = time() . '_' . str_replace(' ', '_', $originalName);

                $fileId = $this->uploadToDrive(
                    $filePathOnDisk,
                    $fileName,
                    $file->getClientMimeType() ?: 'audio/mpeg'
                );

                // ── BƯỚC 2: Cấp quyền public ─────────────────────────────────
                $permission = new Permission([
                    'type' => 'anyone',
                    'role' => 'reader',
                ]);
                $this->drive->permissions->create($fileId, $permission);

                $filePath = "https://drive.google.com/uc?export=download&id={$fileId}";

                // ── BƯỚC 3: Tạo bản ghi trong DB ─────────────────────────────
                $song      = Song::create([
                    'file_path' => $filePath,
                    'file_hash' => $fileHash,
                ]);
                $newSongId = $song->id; // Ghi nhận để rollback nếu bước sau thất bại
            }

            // ── BƯỚC 4: Liên kết bài hát với user ────────────────────────────
            $userSong = $this->linkSongToUser(
                $song,
                $userId,
                $request->custom_name,
                $request->custom_artist
            );

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
            // ── ROLLBACK thủ công (ngược thứ tự tạo) ─────────────────────────

            // Rollback Bước 3: Xóa Song khỏi DB nếu VỪA tạo trong request này
            if ($newSongId) {
                try {
                    Song::destroy($newSongId);
                    Log::info('Rollback: Đã xóa Song khỏi DB', ['song_id' => $newSongId]);
                } catch (\Exception $dbEx) {
                    Log::error('Rollback: Không thể xóa Song khỏi DB', [
                        'song_id' => $newSongId,
                        'error'   => $dbEx->getMessage(),
                    ]);
                }
            }

            // Rollback Bước 1: Xóa file trên Drive nếu đã upload
            if ($fileId) {
                try {
                    $this->drive->files->delete($fileId);
                    Log::info('Rollback: Đã xóa file trên Drive', ['file_id' => $fileId]);
                } catch (\Exception $driveEx) {
                    Log::error('Rollback: Không thể xóa file trên Drive', [
                        'file_id' => $fileId,
                        'error'   => $driveEx->getMessage(),
                    ]);
                }
            }

            Log::error('Lỗi khi upload bài hát: ' . $e->getMessage(), [
                'user_id'     => $userId,
                'file_id'     => $fileId,
                'new_song_id' => $newSongId,
                'trace'       => $e->getTraceAsString(),
            ]);

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
            // Cache-Control cho phép trình duyệt lưu lại file nhạc, nhờ đó khi
            // thiết bị tắt màn hình rồi kết nối lại thì phần đã tải không phải
            // lấy lại từ đầu qua mạng.
            $headers = [
                'Content-Type' => 'audio/mpeg',
                'Content-Length' => $length,
                'Accept-Ranges' => 'bytes',
                'Content-Disposition' => 'inline',
                'Cache-Control' => 'public, max-age=604800',
                'X-Content-Type-Options' => 'nosniff',
            ];

            if ($statusCode === 206) {
                $headers['Content-Range'] = "bytes {$start}-{$end}/{$fileSize}";
            }

            return response()->stream(
                function () use ($stream) {
                    // Client ngắt kết nối (tua bài, đổi bài, mất sóng) thì dừng
                    // ngay thay vì tiếp tục kéo dữ liệu từ Google Drive và giữ
                    // worker của server.
                    ignore_user_abort(false);

                    // Đọc dữ liệu từ stream và gửi đến client
                    while (!feof($stream)) {
                        echo fread($stream, 16384);
                        flush();

                        if (connection_aborted()) {
                            break;
                        }
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

    /**
     * Gan bai hat vao thu vien cua user (tao moi hoac cap nhat ten tuy chinh).
     */
    private function linkSongToUser(Song $song, $userId, string $customName, string $customArtist): UserSong
    {
        $userSong = UserSong::where('user_id', $userId)
            ->where('song_id', $song->id)
            ->first();

        if ($userSong) {
            $userSong->update([
                'custom_name'   => $customName,
                'custom_artist' => $customArtist,
            ]);

            return $userSong;
        }

        return $song->userSongs()->create([
            'user_id'       => $userId,
            'custom_name'   => $customName,
            'custom_artist' => $customArtist,
        ]);
    }

    /**
     * Day file len Google Drive theo tung khoi, doc thang tu dia.
     *
     * Truoc day dung uploadType 'multipart' voi toan bo noi dung file nam san
     * trong mot bien PHP: ton RAM bang dung kich thuoc file, va dut giua chung
     * thi phai lam lai tu dau. Resumable upload chi giu mot khoi 1.5MB trong
     * bo nho tai moi thoi diem.
     */
    private function uploadToDrive(string $path, string $fileName, string $mimeType): string
    {
        $client = $this->drive->getClient();

        $driveFile = new DriveFile();
        $driveFile->setName($fileName);
        $driveFile->setParents([config('services.google_drive.folder_id')]);

        $handle = null;

        // setDefer(true) khien create() tra ve request thay vi gui luon, de
        // MediaFileUpload tu dieu phoi viec gui tung khoi.
        $client->setDefer(true);

        try {
            $createRequest = $this->drive->files->create($driveFile, ['fields' => 'id']);

            $media = new MediaFileUpload(
                $client,
                $createRequest,
                $mimeType,
                null,
                true,
                self::CHUNK_SIZE
            );
            $media->setFileSize(filesize($path));

            $handle = fopen($path, 'rb');
            if ($handle === false) {
                throw new \Exception('Khong the mo file de tai len Drive');
            }

            $result = false;
            while ($result === false && !feof($handle)) {
                $chunk = fread($handle, self::CHUNK_SIZE);
                if ($chunk === false) {
                    throw new \Exception('Loi khi doc file de tai len Drive');
                }
                $result = $media->nextChunk($chunk);
            }

            if (!$result || empty($result->id)) {
                throw new \Exception('Khong nhan duoc file ID sau khi tai len Drive');
            }

            return $result->id;
        } finally {
            if (is_resource($handle)) {
                fclose($handle);
            }
            // setDefer la trang thai cua client dung chung, phai tra lai.
            $client->setDefer(false);
        }
    }

    /**
     * Kiem tra file da co tren he thong chua, dua tren ma hash client tu tinh.
     *
     * Co san thi gan luon vao thu vien cua user va khong phai tai len byte nao.
     * store() cung chong trung bang hash, nhung chi phat hien duoc SAU KHI da
     * nhan xong toan bo file.
     */
    public function checkHash(Request $request)
    {
        $request->validate([
            'file_hash'     => 'required|string|regex:/^[A-Fa-f0-9]{64}$/',
            'custom_name'   => 'required|string|max:255',
            'custom_artist' => 'required|string|max:255',
        ]);

        $song = Song::where('file_hash', strtolower($request->file_hash))->first();

        if (!$song) {
            return response()->json(['exists' => false], 200);
        }

        $userSong = $this->linkSongToUser(
            $song,
            auth()->user()->id,
            $request->custom_name,
            $request->custom_artist
        );

        return response()->json([
            'exists'  => true,
            'message' => 'Bai hat da co san tren he thong, da them vao thu vien cua ban',
            'song'    => [
                'song_id'       => $song->id,
                'custom_name'   => $userSong->custom_name,
                'custom_artist' => $userSong->custom_artist,
                'file_path'     => $song->file_path,
            ],
        ], 201);
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