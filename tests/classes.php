<?php

declare(strict_types=1);

require_once("vendor/autoload.php");

use GQLA\Type;
use GQLA\Field;
use GQLA\Query;
use GQLA\Mutation;

$db = [
    "posts" => [
        1 => [
            "id" => 1,
            "title" => "Hello world!",
            "published" => true,
            "body" => "This is the first post",
            "tags" => ["introduction", "test"],
            "author_id" => 1,
        ],
    ],
    "users" => [
        1 => [
            "id" => 1,
            "name" => "Admin",
        ],
    ],
    "comments" => [
        1 => [
            "id" => 1,
            "post_id" => 1,
            "author_id" => 1,
            "text" => "Hi there :D",
        ],
        2 => [
            "id" => 1,
            "post_id" => 1,
            "author_id" => null,
            "text" => "I'm anonymous o.o",
        ],
    ]
];

#[Type(name: "Post")]
class MyPostClass
{
    #[Field]
    public int $id;
    #[Field]
    public string $title;
    #[Field]
    public bool $published;
    #[Field]
    public string $body;
    #[Field(type: "[String!]!")]
    public array $tags;

    public int $author_id;

    #[Field]
    public function author(): User
    {
        return User::by_id($this->author_id);
    }

    #[Query(name: "post")]
    public static function by_id(int $id): MyPostClass
    {
        global $db;
        $u = new MyPostClass();
        foreach ($db["posts"][$id] as $k => $v) {
            $u->$k = $v;
        }
        return $u;
    }

    #[Mutation]
    public static function create_post(string $title, string $body): MyPostClass
    {
        global $db;
        $p = new MyPostClass();
        $p->title = $title;
        $p->body = $body;
        $db["posts"][] = $p;
        return $p;
    }

    #[Query(name: "posts", type: "[Post!]!")]
    public static function search_posts(): array
    {
        global $db;
        $cs = [];
        foreach ($db["posts"] as $row) {
            $c = new MyPostClass();
            foreach ($row as $k => $v) {
                $c->$k = $v;
            }
            $cs[] = $c;
        }
        return $cs;
    }
}

#[Type]
class User
{
    #[Field]
    public int $id;
    #[Field]
    public string $name;

    public static function by_id(int $id)
    {
        global $db;
        $u = new User();
        foreach ($db["users"][$id] as $k => $v) {
            $u->$k = $v;
        }
        return $u;
    }
}

#[Type]
class Comment
{
    #[Field]
    public int $id;
    #[Field]
    public string $text;

    public int $post_id;
    public ?int $author_id;

    #[Field]
    public function author(): ?User
    {
        return $this->author_id ? User::by_id($this->author_id) : null;
    }

    #[Field(deprecationReason: "Use author subfield")]
    public function author_name(): ?string
    {
        return $this->author_id ? User::by_id($this->author_id)->name : null;
    }

    #[Field(extends: "Post", name: "comments", type: "[Comment!]!")]
    public static function find_comments_on_post(MyPostClass $self): array
    {
        global $db;
        $cs = [];
        foreach ($db["comments"] as $row) {
            if ($row["post_id"] == $self->id) {
                $c = new Comment();
                foreach ($row as $k => $v) {
                    $c->$k = $v;
                }
                $cs[] = $c;
            }
        }
        return $cs;
    }
}

#[Mutation]
function login(string $username, string $password): User
{
    return new User();
}

#[Mutation]
function logout(): bool
{
    return true;
}
