<?php

namespace App\Http\Controllers;

use Google\Service\Drive;
use Illuminate\Http\Request;
use Google\Http\MediaFileUpload;

class TestController extends Controller
{
    protected $drive;

    public function __construct(Drive $drive)
    {
        $this->drive = $drive;
    }

    public function showForm()
    {
        return view('Test');
    }

    public function upload(Request $request)
    {
        $request->validate([
            'song' => 'required|file|mimes:mp3|max:10240', // Giới hạn 10MB
        ]);

        try {
            $file = $request->file('song');
            $fileContent = file_get_contents($file->getRealPath());

            $fileMetadata = new Drive\DriveFile([
                'name' => $file->getClientOriginalName(),
                'parents' => [config('services.google_drive.folder_id')],
            ]);
            $driveFile = $this->drive->files->create($fileMetadata, [
                'data' => $fileContent,
                'mimeType' => $file->getMimeType(),
                'uploadType' => 'multipart',
            ]);

            $this->drive->permissions->create($driveFile->id, new Drive\Permission([
                'type' => 'anyone',
                'role' => 'reader',
            ]));

            $fileUrl = "https://drive.google.com/uc?id={$driveFile->id}";
            return redirect()->back()->with([
                'message' => "File uploaded successfully! URL: <a href='$fileUrl' target='_blank'>$fileUrl</a>",
                'fileId' => $driveFile->id // Truyền fileId để dùng cho download
            ]);
        } catch (\Exception $e) {
            return redirect()->back()->with('error', 'Error: ' . $e->getMessage());
        }
    }

    public function download($fileId)
{
    try {
        // Lấy file nội dung từ Google Drive
        $httpResponse = $this->drive->files->get($fileId, ['alt' => 'media']);
        $tempFile = tempnam(sys_get_temp_dir(), 'google_drive_file_'); // Tạo file tạm
        file_put_contents($tempFile, $httpResponse->getBody()->getContents()); // Ghi nội dung vào file tạm

        // Lấy metadata
        $fileMetadata = $this->drive->files->get($fileId);
        $fileName = $fileMetadata->getName();
        $mimeType = $fileMetadata->getMimeType() ?? 'audio/mpeg';

        // Đảm bảo file không trống
        if (!file_exists($tempFile) || filesize($tempFile) === 0) {
            throw new \Exception('File content is empty or invalid.');
        }

        // Trả file cho client
        return response()->streamDownload(function () use ($tempFile) {
            readfile($tempFile);
        }, $fileName, [
            'Content-Type' => $mimeType,
        ])->deleteFileAfterSend(); // Xóa file tạm sau khi gửi
    } catch (\Exception $e) {
        return redirect()->back()->with('error', 'Download error: ' . $e->getMessage());
    }
}
}
