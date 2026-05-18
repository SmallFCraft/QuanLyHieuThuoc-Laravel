<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable;

    /**
     * The primary key associated with the table.
     *
     * @var string
     */
    protected $primaryKey = 'MaKH';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'birth_date',
        'gender',
        'address',
        'phone',
        'password',
        'role',
        'status',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'birth_date' => 'date',
            'password' => 'hashed',
        ];
    }
    
    /**
     * Check if user is an administrator
     *
     * @return bool
     */
    public function isAdministrator(): bool
    {
        return $this->role === 'Administrator';
    }
    
    /**
     * Check if user account is active
     *
     * @return bool
     */
    public function isActive(): bool
    {
        return $this->status === 'active';
    }
    
    /**
     * Get orders for this user
     */
    public function orders()
    {
        return $this->hasMany(Order::class, 'MaKH', 'MaKH');
    }
    
    /**
     * Get audit logs for this user
     */
    public function auditLogs()
    {
        return $this->hasMany(AuditLog::class, 'MaKH', 'MaKH');
    }
    
    /**
     * Get chat messages for this user
     */
    public function chatMessages()
    {
        return $this->hasMany(ChatMessage::class, 'MaKH', 'MaKH');
    }
    
    /**
     * Get chatbot images for this user
     */
    public function chatbotImages()
    {
        return $this->hasMany(ChatbotImage::class, 'MaKH', 'MaKH');
    }
}
