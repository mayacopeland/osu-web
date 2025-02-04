<?php

// Copyright (c) ppy Pty Ltd <contact@ppy.sh>. Licensed under the GNU Affero General Public License v3.0.
// See the LICENCE file in the repository root for full licence text.

namespace App\Models\OAuth;

use App\Events\UserSessionEvent;
use App\Exceptions\InvalidScopeException;
use App\Models\Traits\FasterAttributes;
use App\Models\User;
use Ds\Set;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Laravel\Passport\RefreshToken;
use Laravel\Passport\Token as PassportToken;

class Token extends PassportToken
{
    // PassportToken doesn't have factory
    use HasFactory, FasterAttributes;

    private ?Set $scopeSet;

    public function refreshToken()
    {
        return $this->hasOne(RefreshToken::class, 'access_token_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Whether the resource owner is delegated to the client's owner.
     *
     * @return bool
     */
    public function delegatesOwner(): bool
    {
        return $this->scopeSet()->contains('delegate');
    }

    public function getAttribute($key)
    {
        return match ($key) {
            'client_id',
            'id',
            'name',
            'user_id' => $this->getRawAttribute($key),

            'revoked' => (bool) $this->getRawAttribute($key),
            'scopes' => json_decode($this->getRawAttribute($key), true),

            'created_at',
            'expires_at',
            'updated_at' => $this->getTimeFast($key),

            'client',
            'refreshToken',
            'user' => $this->getRelationValue($key),
        };
    }

    /**
     * Resource owner for the token.
     *
     * For client_credentials grants, this is the client that requested the token;
     * otherwise, it is the user that authorized the token.
     */
    public function getResourceOwner(): ?User
    {
        if ($this->isClientCredentials() && $this->delegatesOwner()) {
            return $this->client->user;
        }

        return $this->user;
    }

    public function isClientCredentials()
    {
        // explicitly no user_id.
        return $this->user_id === null;
    }

    public function isOwnToken(): bool
    {
        $clientUserId = $this->client->user_id;

        return $clientUserId !== null && $clientUserId === $this->user_id;
    }

    public function revokeRecursive()
    {
        $result = $this->revoke();
        $this->refreshToken?->revoke();

        return $result;
    }

    public function revoke()
    {
        $saved = parent::revoke();

        if ($saved && $this->user_id !== null) {
            UserSessionEvent::newLogout($this->user_id, ["oauth:{$this->getKey()}"])->broadcast();
        }

        return $saved;
    }

    public function scopeValidAt($query, $time)
    {
        return $query->where('revoked', false)->where('expires_at', '>', $time);
    }

    public function setScopesAttribute(?array $value)
    {
        if ($value !== null) {
            sort($value);
        }

        $this->scopeSet = null;
        $this->attributes['scopes'] = $this->castAttributeAsJson('scopes', $value);
    }

    public function validate(): void
    {
        static $scopesRequireDelegation = new Set(['chat.write', 'chat.write_manage', 'delegate']);

        $scopes = $this->scopeSet();
        if ($scopes->isEmpty()) {
            throw new InvalidScopeException('Tokens without scopes are not valid.');
        }

        $client = $this->client;
        if ($client === null) {
            throw new InvalidScopeException('The client is not authorized.', 'unauthorized_client');
        }

        // no silly scopes.
        if ($scopes->contains('*') && $scopes->count() > 1) {
            throw new InvalidScopeException('* is not valid with other scopes');
        }

        if ($this->isClientCredentials()) {
            if ($scopes->contains('*')) {
                throw new InvalidScopeException('* is not allowed with Client Credentials');
            }

            if ($this->delegatesOwner() && !$client->user->isBot()) {
                throw new InvalidScopeException('Delegation with Client Credentials is only available to chat bots.');
            }

            if (!$scopes->intersect($scopesRequireDelegation)->isEmpty()) {
                if (!$this->delegatesOwner()) {
                    throw new InvalidScopeException('delegate scope is required.');
                }

                // delegation is only allowed if scopes given allow delegation.
                if (!$scopes->diff($scopesRequireDelegation)->isEmpty()) {
                    throw new InvalidScopeException('delegation is not supported for this combination of scopes.');
                }
            }
        } else {
            // delegation is only available for client_credentials.
            if ($this->delegatesOwner()) {
                throw new InvalidScopeException('delegate scope is only valid for client_credentials tokens.');
            }

            // only clients owned by bots are allowed to act on behalf of another user.
            // the user's own client can send messages as themselves for authorization code flows.
            static $ownClientScopes = new Set([
                'chat.read',
                'chat.write',
                'chat.write_manage',
            ]);
            if (!$scopes->intersect($ownClientScopes)->isEmpty() && !($this->isOwnToken() || $client->user->isBot())) {
                throw new InvalidScopeException('This scope is only available for chat bots or your own clients.');
            }
        }
    }

    public function save(array $options = [])
    {
        // Forces error if passport tries to issue an invalid client_credentials token.
        $this->validate();

        return parent::save($options);
    }

    private function scopeSet(): Set
    {
        return $this->scopeSet ??= new Set($this->scopes ?? []);
    }
}
