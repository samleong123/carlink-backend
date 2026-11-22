<?php
namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Pagination\Cursor;
use Illuminate\Validation\ValidationException;

final class CursorPage
{
    public static function options(Request $request, int $maximum=100, array $fields=['id']): array
    {
        $data=$request->validate(['per_page'=>"nullable|integer|min:1|max:$maximum",'cursor'=>'nullable|string|max:2048']);
        $cursor=null;
        if (isset($data['cursor']) && $data['cursor'] !== '') {
            try { $cursor=self::decode($data['cursor'],$fields); }
            catch (\Throwable $e) { $cursor=null; }
            if (!$cursor) throw ValidationException::withMessages(['cursor'=>['Invalid pagination cursor.']]);
        }
        return [$data['per_page'] ?? min(50,$maximum),$cursor];
    }

    public static function decode(string $encoded, array $fields): Cursor
    {
        $cursor=Cursor::fromEncoded($encoded);
        if (!$cursor || !is_bool($cursor->pointsToNextItems())) throw new \InvalidArgumentException();
        foreach ($fields as $field) if (!is_string($cursor->parameter($field)) || $cursor->parameter($field) === '' || strlen($cursor->parameter($field))>100) throw new \InvalidArgumentException();
        return $cursor;
    }

    public static function metadata($page): array
    {
        return ['next_cursor'=>$page->nextCursor()?->encode(),'previous_cursor'=>$page->previousCursor()?->encode(),'per_page'=>$page->perPage()];
    }
}
