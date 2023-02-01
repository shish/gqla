# gqla
A GraphQL schema generator based on PHP Annotations

If your webapp already has objects for internal use, you can easily turn
them into graphql objects and query them remotely, eg

```php
#[GraphQLObject]
class Post {
    #[GraphQLField]
    public string $title;
    #[GraphQLField]
    public string $body;
    public int $author_id;
    #[GraphQLField(name: "author")]
    public function get_author(): User {
        return User::by_id($this->author_id);
    }

    #[GraphQLQuery(name: "posts", type: "[Post!]!")]
    public static function search_posts(string $text): array {
        // SELECT * FROM posts WHERE text LIKE "%{$text}%";
    }
}

#[GraphQLObject]
class User {
    #[GraphQLField]
    public string $name;
}

#[GraphQLObject]
class Comment {
    #[GraphQLField]
    public string $text;
    public int $post_id;
    public int $author_id;
    #[GraphQLField]
    public function author(): User {
        return User::by_id($this->author_id);
    }
    #[GraphQLField(deprecationReason: "Use author subfield")]
    public function author_name(): string {
        return User::by_id($this->author_id)->name;
    }
    #[GraphQLField(extends: "Post", type: "[Comment!]!")]
    public function comments(Post $post): array {
        // SELECT * FROM comments WHERE post_id = {$post->id}
    }
}
```

This creates a graphql schema

```graphql
type Post {
    title: String!
    body: String!
    author: User!
    comments: [Comment!]!
}

type User {
    name: String!
}

type Comment {
    text: String!
    author: User!
    author_name: String @deprecated(reason: "Use author subfield")
}

type Query {
    search_posts(text: String!): [Post!]!
}
```

So you can send a query like

```graphql
{
    search_posts(text: "Hello") {
        title
        body
        author {
            name
        }
        comments {
            text
        }
    }
}
```

And get a response like

```json
{
    "data": {
        "posts": [
            {
                "title": "Hello world!",
                "body": "This is the first post in my blog",
                "author": {
                    "name": "Shish",
                },
                "comments": [
                    { "text": "Nice first post!" },
                    { "text": "It works :D" },
                ],
            }
        ]
    }
}
```

## API

For creating `ObjectType`s, annotate a class with

```php
use GQLA\GraphQLObject;

// name: use a specific GraphQL type name (default: use the class name)
#[GraphQLObject(name: "Foo")]
class MyFooClass {
    ...
}
```

For adding a field to an `ObjectType`, annotate either a property or a
function with `GraphQLField`

```php
#[GraphQLObject]
class MyFooClass {
    // name: use a specific GraphQL type name (default: use the property / function)
    // type: use a specific type (default: use reflection to look at args).
    //       Note that you *must* specify a type whenever the PHP function returns
    //       an array, because PHP arrays are untyped
    #[GraphQLField(name: "Bar", type: "[String!]!")]
    public array $tags;

    // You can also use GraphQLField on class methods, and then this function
    // will be called when (and only when) somebody queries this specific field
    #[GraphQLField]
    function do_the_bar(): SomeOtherClass {
        ...
    }
}
```

For defining a new query or mutation, `GraphQLQuery` / `GraphQLMutation`:

```php
#[GraphQLObject]
class MyFooClass {

}
