<?php

namespace App\Models;

use Database\Factories\RoleFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Role extends Model
{
    /** @use HasFactory<RoleFactory> */
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'slug',
        'description',
        'is_system',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_system' => 'boolean',
        ];
    }

    /**
     * Users that have this role.
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withTimestamps();
    }

    /**
     * Permissions assigned to this role.
     */
    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class)->withTimestamps();
    }

    /**
     * Assign a permission to this role.
     */
    public function givePermissionTo(Permission|string $permission): self
    {
        $permissionModel = is_string($permission)
            ? Permission::firstOrCreate(['slug' => $permission], ['name' => ucfirst($permission), 'group' => 'general'])
            : $permission;

        $this->permissions()->syncWithoutDetaching([$permissionModel->id]);

        return $this;
    }

    /**
     * Revoke a permission from this role.
     */
    public function revokePermissionTo(Permission|string $permission): self
    {
        $permissionId = is_string($permission)
            ? Permission::where('slug', $permission)->value('id')
            : $permission->id;

        if ($permissionId) {
            $this->permissions()->detach($permissionId);
        }

        return $this;
    }

    /**
     * Check if the role has a given permission.
     */
    public function hasPermission(string $permissionSlug): bool
    {
        return $this->permissions()->where('slug', $permissionSlug)->exists();
    }
}
