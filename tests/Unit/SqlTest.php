<?php

declare(strict_types=1);

use Dskripchenko\Telemetry\Support\Sql;

it('keeps a statement\'s shape and none of its values', function (string $sql, string $shape): void {
    expect(Sql::shape($sql))->toBe($shape);
})->with([
    'bound parameters stay' => ['select * from "users" where "id" = ? limit 1', 'select * from "users" where "id" = ? limit ?'],
    'strings and numbers go' => ["select * from users where email = 'ada@example.test' and age > 30", 'select * from users where email = ? and age > ?'],
    'a quote inside a string' => ["update notes set body = 'it''s late', n = -2.5 where id = 7", 'update notes set body = ?, n = ? where id = ?'],
    'a list of any length is one' => ['select * from "tasks" where "id" in (?, ?, ?, ?)', 'select * from "tasks" where "id" in (?+)'],
    'rows of an insert are one' => ['insert into "t" ("a", "b") values (?, ?), (?, ?), (?, ?)', 'insert into "t" ("a", "b") values (?+)+'],
    'numbered placeholders' => ['select * from t where a = $1 and b = $2', 'select * from t where a = ? and b = ?'],
    'digits in names stay' => ['select "t1"."oauth2_id" from "oauth2_clients" as "t1"', 'select "t1"."oauth2_id" from "oauth2_clients" as "t1"'],
    'whitespace is one space' => ["select *\n  from t\n  where a = 1", 'select * from t where a = ?'],
]);

it('cuts a very long statement', function (): void {
    $shape = Sql::shape('select '.implode(', ', array_map(static fn (int $i): string => '"column_number_'.$i.'_x"', range(1, 80))).' from t');

    expect(mb_strlen($shape))->toBe(601)->and($shape)->toEndWith('…');
});
