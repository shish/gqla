# gqla
A GraphQL schema generator based on PHP Annotations

The schemas generated here are based on https://github.com/webonyx/graphql-php,
so once you've generated a schema, look at the docs over there for how
to make use of it :)

If your webapp already has objects for internal use, you can easily turn
them into graphql objects and query them remotely, eg

```php
use GQLA\Expose;

// To create a new GraphQL Type, expose a PHP class
#[Expose]
class Post {
    // To add fields to that type, expose PHP class properties
    #[Expose]
    public string $title;
    #[Expose]
    public string $body;

    // If you don't want the field to be part of your API,
    // don't expose it
    public int $author_id;

    // If your field is more complicated than a property,
    // expose a PHP function and it will be called as needed
    #[Expose(name: "author")]
    public function get_author(): User {
        return User::by_id($this->author_id);
    }

    // To add a new query or mutation, you can extend
    // the base Query or Mutation types
    #[Expose(extends: "Query", name: "posts", type: "[Post!]!")]
    public static function search_posts(string $text): array {
        // SELECT * FROM posts WHERE text LIKE "%{$text}%";
    }
}

#[Expose]
class User {
    #[Expose]
    public string $name;
}

#[Expose]
class Comment {
    #[Expose]
    public string $text;
    public int $post_id;
    public int $author_id;
    #[Expose]
    public function author(): User {
        return User::by_id($this->author_id);
    }
    #[Expose(deprecationReason: "Use author subfield")]
    public function author_name(): string {
        return User::by_id($this->author_id)->name;
    }

    // Note that even if the Comment class comes from a third-party
    // plugin, it can still add a new "comments" field onto the
    // first-party "Post" object type.
    #[Expose(extends: "Post", type: "[Comment!]!")]
    public function comments(Post $post): array {
        // SELECT * FROM comments WHERE post_id = {$post->id}
    }
}
```

Then `\GQLA\genSchema()` will search for all annotated objects and
return a graphql schema like:

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

`Expose` takes several parameters:

- `name`: Give a specific name to this type / field
  (default: Use the class / property / function name)
- `type`: Give a specific type to the field
  (default: infer this from the PHP-types-to-GraphQL-types map)
  - Note that if your PHP type is `array`, then you must specify `type` with
    something more specific for GraphQL, for example:
    ```php
    #[Expose(type: "[String!]!")]
    function get_tags(): array {
        return ["foo", "bar"];
    }
    ```
- `args`: Override the inferred types for any function arguments
  - As with type, note that this is required whenever a PHP function
    accepts an array as input, for example:
    ```php
    #[Expose(args: ["tags" => "[String!]!"])]
    function get_first_post_with_tags(array $tags): Post {
        return ...;
    }
    ```
- `extends`: By default, an exposed field on an exposed object will be
  added as a field of that object. But to add a new root query you need
  to extend `Query`, or you can also extend your other objects (eg, a
  "likes" plugin could add a `number_of_likes` field onto your `BlogPost`
  object)
- `description`: Add a description to your GraphQL schema for anybody
  who wants to develop client apps
- `deprecationReason`: Mark this field as deprecated

