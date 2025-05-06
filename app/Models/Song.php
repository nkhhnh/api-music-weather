<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Song extends Model
{
    use HasFactory;
    protected $fillable = [
        'file_path','file_hash'
    ];
    public function userSongs()
    {
        return $this->hasMany(UserSong::class, 'song_id');
    }

    public function albums()
    {
        return $this->belongsToMany(Album::class, 'album_song', 'song_id', 'album_id');
    }
}
