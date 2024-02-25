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

class FakeDB
{
    public static array $db = [
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
}

#[InterfaceType(name: "Node")]
class Node
{
    #[Field]
    public string $id;

    #[Query(args: ["id" => "ID!"])]
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
        $u = new MyPostClass();
        foreach (FakeDB::$db["posts"][$id] as $k => $v) {
            $u->$k = $v;
        }
        return $u;
    }

    #[Mutation]
    public static function create_post(string $title, string $body): MyPostClass
    {
        $p = new MyPostClass();
        $p->title = $title;
        $p->body = $body;
        FakeDB::$db["posts"][] = $p;
        return $p;
    }

    /**
     * @return MyPostClass[]
     */
    #[Query(name: "posts", type: "[Post!]!")]
    public static function search_posts(): array
    {
        $cs = [];
        foreach (FakeDB::$db["posts"] as $row) {
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

    // private to avoid direct construction, use by_id() instead
    private function __construct()
    {
    }

    public static function new(int $id, string $name): User
    {
        $u = new User();
        $u->id = $id;
        $u->name = $name;
        return $u;
    }

    public static function by_id(int $id): User
    {
        $u = new User();
        $u->id = FakeDB::$db["users"][$id]["id"];
        $u->name = FakeDB::$db["users"][$id]["name"];
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
        return User::new(42, $input->username);
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
        $cs = [];
        foreach (FakeDB::$db["comments"] as $row) {
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
    return User::by_id(1);
}

#[Mutation]
function logout(): bool
{
    return true;
}
