# gqla
A GraphQL schema generator based on PHP Annotations

If your webapp already has objects for internal use, you can easily turn
them into graphql objects and query them remotely, eg

```php
#[GraphQLObject]
class Post
{
    #[GraphQLField]
    public string $title;
    #[GraphQLField]
    public string $body;
    public int $author_id;
    #[GraphQLField(name: "author")]
    public function get_author(): User
    {
        return User::by_id($this->author_id);
    }

    #[GraphQLQuery(name: "posts", type: "[Post!]!")]
    public static function search_posts(string $text): array
    {
        // ... query the database and return
        // an array of Post objects ...
    }
}

#[GraphQLObject]
class User
{
    #[GraphQLField]
    public string $name;
}

#[GraphQLObject]
class Comment
{
    #[GraphQLField]
    public string $text;
    public int $post_id;
    public int $author_id;
    #[GraphQLField]
    public function author(): User
    {
        return User::by_id($this->author_id);
    }
}
```

This creates a graphql schema

```graphql
type Post {
    title: String!
    body: String!
    author: User!
}

type User {
    name: String!
}

type Comment {
    text: String!
    author: User!
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