<?php

declare(strict_types=1);

use GQLA\GraphQLObject;
use GQLA\GraphQLField;
use GQLA\GraphQLQuery;
use GQLA\GraphQLMutation;

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

#[GraphQLObject]
class Post
{
    #[GraphQLField]
    public int $id;
    #[GraphQLField]
    public string $title;
    #[GraphQLField]
    public bool $published;
    #[GraphQLField]
    public string $body;
    #[GraphQLField(type: "[String!]!")]
    public array $tags;

    public int $author_id;

    #[GraphQLField]
    public function author(): User
    {
        return User::by_id($this->author_id);
    }

    #[GraphQLQuery(name: "post")]
    public static function by_id(int $id): Post
    {
        global $db;
        $u = new Post();
        foreach ($db["posts"][$id] as $k => $v) {
            $u->$k = $v;
        }
        return $u;
    }

    #[GraphQLMutation]
    public static function create_post(string $title, string $body): Post
    {
        global $db;
        $p = new Post();
        $p->title = $title;
        $p->body = $body;
        $db["posts"][] = $p;
        return $p;
    }

    #[GraphQLQuery(name: "posts", type: "[Post!]!")]
    public static function search_posts(): array
    {
        global $db;
        $cs = [];
        foreach ($db["posts"] as $row) {
            $c = new Post();
            foreach ($row as $k => $v) {
                $c->$k = $v;
            }
            $cs[] = $c;
        }
        return $cs;
    }
}

#[GraphQLObject]
class User
{
    #[GraphQLField]
    public int $id;
    #[GraphQLField]
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

#[GraphQLObject]
class Comment
{
    #[GraphQLField]
    public int $id;
    #[GraphQLField]
    public string $text;

    public int $post_id;
    public ?int $author_id;

    #[GraphQLField]
    public function author(): ?User
    {
        return $this->author_id ? User::by_id($this->author_id) : null;
    }

    #[GraphQLField(deprecationReason: "Use author subfield")]
    public function author_name(): ?string
    {
        return $this->author_id ? User::by_id($this->author_id)->name : null;
    }

    #[GraphQLField(extends: "Post", name: "comments", type: "[Comment!]!")]
    public static function find_comments_on_post(Post $self): array
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

#[GraphQLMutation]
function login(string $username, string $password): User
{
    return new User();
}

#[GraphQLMutation]
function logout(): bool
{
    return true;
}
