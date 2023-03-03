<?php

declare(strict_types=1);

namespace Demo;

use GQLA\InterfaceType;
use GQLA\Enum;
use GQLA\Type;
use GQLA\Field;
use GQLA\Query;
use GQLA\Mutation;
use GQLA\InputObjectType;

#[Enum]
enum State: string
{
    case Draft = "draft";
    case Review = "review";
    case Published = "published";
}

$db = [
    "posts" => [
        1 => [
            "id" => 1,
            "title" => "Hello world!",
            "state" => State::Published,
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

#[InterfaceType(name: "Node")]
class Node
{
    #[Field]
    public string $id;

    #[Query(args: ["id"=>"ID!"])]
    public function node(string $id): Node
    {
        return new Node();
    }
}

#[Type(name: "Post", interfaces: ["Node"])]
class MyPostClass
{
    #[Field(name: "post_id")]
    public int $id;
    #[Field]
    public string $title;
    #[Field]
    public State $state;
    #[Field]
    public string $body;
    /** @var string[] */
    #[Field(type: "[String!]!")]
    public array $tags;

    public int $author_id;

    #[Field(name: "id", type: "ID!")]
    public function node_id(): string
    {
        return "post:{$this->id}";
    }

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

    /**
     * @return MyPostClass[]
     */
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

#[Type(interfaces: ["Node"])]
class User
{
    #[Field(name: "user_id")]
    public int $id;
    #[Field]
    public string $name;

    public static function by_id(int $id): User
    {
        global $db;
        $u = new User();
        foreach ($db["users"][$id] as $k => $v) {
            $u->$k = $v;
        }
        return $u;
    }

    #[Field(name: "id", type: "ID!")]
    public function node_id(): string
    {
        return "user:{$this->id}";
    }

    #[Field]
    public function add_id(int $n): int
    {
        return $this->id + $n;
    }
}

#[InputObjectType]
class CreateUserInputs
{
    public function __construct(
        public string $username,
        public string $password,
        public string $email = "no@example.com",
    ) {
    }

    #[Mutation]
    public static function create_user(CreateUserInputs $input): User
    {
        $u = new User();
        $u->id = 42;
        $u->name = $input->username;
        return $u;
    }
}

#[Type]
class Comment
{
    #[Field(name: "comment_id")]
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

    /**
     * @return Comment[]
     */
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

    #[Field(extends: "User")]
    public static function add_comment_id(User $user, int $n): int
    {
        return $user->id + $n;
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
