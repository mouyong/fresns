<?php

/*
 * Fresns (https://fresns.org)
 * Copyright (C) 2021-Present Jarvis Tang
 * Released under the Apache-2.0 License.
 */

namespace App\Fresns\Subscribe;

use App\Models\Config;

class Subscribe
{
    protected array $wordBody;

    const SUBSCRIBE_TYPE_TABLE_DATA_CHANGE = 1;
    const SUBSCRIBE_TYPE_USER_ACTIVATE = 2;

    protected int $type;
    protected string $unikey;
    protected string $cmdWord;
    protected ?string $subTableName;

    public function __construct(array $wordBody = [])
    {
        if ($wordBody) {
            $this->validate($wordBody);

            $this->wordBody = $wordBody;

            $this->type = $this->getItem('type');
            $this->unikey = $this->getItem('unikey');
            $this->cmdWord = $this->getItem('cmdWord');
            $this->setSubTableName($this->getItem('subTableName'));
        }
    }

    public function validate(array $wordBody)
    {
        \validator()->validate($wordBody, [
            'type' => 'required|int',
            'unikey' => 'required|string',
            'cmdWord' => 'required|string',
            'subTableName' => 'nullable',
        ]);
    }

    public function getItem(string $field, $default = null)
    {
        return $this->wordBody[$field] ?? $default;
    }

    public static function make(array $wordBody = [])
    {
        return new static($wordBody);
    }

    public function getType()
    {
        return $this->type;
    }

    public function getUnikey()
    {
        return $this->unikey;
    }

    public function getCmdWord()
    {
        return $this->cmdWord;
    }

    public function setSubTableName(string $subTableName)
    {
        $this->subTableName = qualifyTableName($subTableName);
    }

    public function getSubTableName()
    {
        return $this->subTableName;
    }

    /**
     * Table name verification: support for subscribed table names.
     *
     * @param  string  $table
     * @return bool
     *
     * @see https://fresns.org/extensions/command-word/subscribe.html
     */
    protected function isAvaliableTableName(): bool
    {
        $tableNames = [
            'accounts',
            'users',
            'groups',
            'hashtags',
            'posts',
            'comments',
            'files',
            'conversation_messages',
        ];

        return in_array($this->getSubTableName(), $tableNames);
    }

    public function supportSubscribe()
    {
        return $this->isAvaliableTableName();
    }

    public function isNotSupportSubscribe()
    {
        return ! $this->supportSubscribe();
    }

    public function same(Subscribe $subscribe)
    {
        return $this->getType() === $subscribe->getType()
            && $this->getUnikey() === $subscribe->getUnikey()
            && $this->getCmdWord() === $subscribe->getCmdWord()
            && $this->getSubTableName() === $subscribe->getSubTableName();
    }

    public function notSame(Subscribe $subscribe)
    {
        return ! $this->same($subscribe);
    }

    protected function filterDataByWordBody()
    {
        return collect($this->getCurrentSubscribes())
            ->map(fn ($item) => static::make($item))
            ->filter(fn ($item) => $item->notSame($this));
    }

    public function ensureSubscribeNotExists()
    {
        return ! $this->ensureSubscribeExists();
    }

    public function ensureSubscribeExists()
    {
        $filterData = $this->filterDataByWordBody();

        return $filterData->count() !== collect($this->getCurrentSubscribes())->count();
    }

    public function toArray(): array
    {
        return [
            'type' => $this->getType(),
            'unikey' => $this->getUnikey(),
            'cmdWord' => $this->getCmdWord(),
            'subTableName' => $this->getSubTableName(),
        ];
    }

    public function getSubscribeItemConfig()
    {
        try {
            return Config::withTrashed()->where('item_key', 'subscribe_items')->firstOrFail();
        } catch (\Throwable $e) {
            throw new \RuntimeException('Cannot find item_key subscribe_items: '.$e->getMessage());
        }
    }

    public function getCurrentSubscribes()
    {
        return $this->getSubscribeItemConfig()->item_value;
    }

    public function getTableDataChangeSubscribes()
    {
        return collect($this->getCurrentSubscribes())
            ->map(fn ($item) => static::make($item))
            ->filter(fn ($item) => $item->getType() === static::SUBSCRIBE_TYPE_TABLE_DATA_CHANGE);
    }

    public function getUserActivateSubscribes()
    {
        return collect($this->getCurrentSubscribes())
            ->map(fn ($item) => static::make($item))
            ->filter(fn ($item) => $item->getType() === static::SUBSCRIBE_TYPE_USER_ACTIVATE);
    }

    public function save()
    {
        $subscribes = $this->getCurrentSubscribes();

        array_push($subscribes, $this->toArray());

        return $this->getSubscribeItemConfig()->update([
            'item_value' => $subscribes,
        ]);
    }

    public function remove()
    {
        $filterData = $this->filterDataByWordBody();

        $filterData = $filterData->flatten()->map->toArray()->toArray();

        $this->getSubscribeItemConfig()->update([
            'item_value' => $filterData,
        ]);
    }
}
