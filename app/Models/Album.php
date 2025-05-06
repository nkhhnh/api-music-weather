<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Album extends Model
{
    use HasFactory;
    protected $fillable = ['album_name', 'user_id'];

    
    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    
    public function songs()
    {
        return $this->belongsToMany(Song::class, 'album_song', 'album_id', 'song_id');
    }
}
