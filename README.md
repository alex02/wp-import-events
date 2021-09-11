# wp-import-events

You can access the demo project from here: [https://stage.alexnet.co/loop/](https://stage.alexnet.co/loop/)

I've used Metabox for creating an 'event' post type and the custom fields.

For the plugin to work, an 'event' post type should be created, and `id`, `about`, `email`, `organizer`, `timestamp`, `address`, `latitude`, `longitude`, and `tags` custom fields should be created and attached to it. 

After the initial setup, here are the steps to use it:

1. Upload and activate the Loop Demo plugin.
2. Go to Tools → Import Events to import data.
3. Select a JSON file and upload it.

The plugin adds json in the allowed mime types, but still WordPress may need to be allowed to upload unfiltered files by adding this to the wp-config.php:

`define( 'ALLOW_UNFILTERED_UPLOADS', true );`

## Show Data

I've created a shortcode that displays all the active events, sorted by closest date.
Add the shortcode [view_events] to any page to view them.

Live demo: [https://stage.alexnet.co/loop/view-events/](https://stage.alexnet.co/loop/view-events/)

## Export Data

I've created a REST API route to access the active events in JSON format. Since the active events are all public, I haven't set any permission check.
It can be accessed through: {site}/wp-json/events/v2/view/

Live demo: [https://stage.alexnet.co/loop/wp-json/events/v2/view/](https://stage.alexnet.co/loop/wp-json/events/v2/view/)

## CLI integration

Use `wp events import` to import events. It supports both local files or URLs. It also supports `--skip-email` flag to suppress sending an email upon successful import.

Example:

```
wp events import /wp-content/uploads/data.json

wp events import https://alex.net.co/data.json

wp events import —file=/wp-content/uploads/data.json

wp events import —file=https://alex.net.co/data.json —skip-email
```
