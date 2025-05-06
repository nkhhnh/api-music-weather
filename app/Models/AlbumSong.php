<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AlbumSong extends Model
{
    use HasFactory;
    protected $table = 'album_song';

    protected $fillable = [
        'album_id',
        'song_id',
    ];

    
    public function album()
    {
        return $this->belongsTo(Album::class, 'album_id');
    }

    
    public function song()
    {
        return $this->belongsTo(Song::class, 'song_id');
    }
}
