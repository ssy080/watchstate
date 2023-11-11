# WatchState

This tool primary goal is to sync your backends play state without relying on third party services,
out of the box, this tool support `Jellyfin`, `Plex` and `Emby` media servers.

## Feature updates

### 2023-11-11

We added new feature `watch progress tracking` YAY which works exclusively via webhooks at the moment to keep tracking of your play progress.
As this feature is quite **EXPERIMENTAL** we have separate command and task for it `state:progress` will send back progress to your backends.
However, Sadly this feature is not working at the moment with `Jellyfin` due to API bug [#10567](https://github.com/jellyfin/jellyfin/issues/10567) . Once `Jellyfin` fixes the bug it will start working automatically
as the codebase already has the required code in place. However, the feature works well with both `Plex` and `Emby`.

We would like to support this feature via standard `import` & `export` routine, but sadly that proven to be quite difficult due to the early design of the tool.
However, if i get enough time i will try to implement it. But for now, The progress tracking is done via webhooks. However, the `state:progress` command will
push the update to all `export` enabled backends.

This new feature is still experimental it's important to keep backup of your watch state, which can be done via `state:backup` command. we suggest enabling auto backup.

This feature like other is disabled by default, and you can enable it via the env variable `WS_CRON_PROGRESS=1`.

On another point, we have decided to enable backup by default. To disable it simply add new environment variable `WS_CRON_BACKUP=0`.

### 2023-10-31

We added new command called `db:parity` which will check if your backends are reporting the same data.

# Features

* Sync backends play state (from many to many).
* Backup your backends play state into `portable` format.
* Receive Webhook events from media backends.
* Find `un-matched` or `mis-matched` items.
* Search your backend for `title` or `item id`.
* Display and filter your play state. Can be exported as `yaml` or `json`.
* Check if your media servers reporting same data via the parity command.
* Track your watch progress via webhooks.

----

# Install

create your `docker-compose.yaml` with the following content:

```yaml
services:
    watchstate:
        image: ghcr.io/arabcoders/watchstate:latest
        # To change the user/group id associated with the tool change the following line.
        user: "${UID:-1000}:${GID:-1000}"
        container_name: watchstate
        restart: unless-stopped
        # For information about supported environment variables visit FAQ page.
        # works for both global and container specific environment variables. 
        environment:
            - WS_TZ=UTC # Set timezone.
        ports:
            - "8080:8080" # webhook listener port.
        volumes:
            - ./data:/config:rw # mount current directory to container /config directory.
```

Create directory called `data` next to the `docker-compose.yaml` file. After creating your docker compose file, start the container.

```bash
$ mkdir -p ./data && docker-compose pull && docker-compose up
```

> [!IMPORTANT]
> It's really important to match the `user:` to the owner of the `data` directory, the container is rootless, as such
> it will crash if it's unable to write to the data directory. It's really not recommended to run containers as root,
> but if you fail to run the container you can try setting the `user: "0:0"` if that works it means you have permissions
> issues. refer to [FAQ](FAQ.md) to troubleshoot the problem.

> [!NOTE]
> For `Unraid` users You can install the `Community Applications` plugin, and search for `watchstate` it comes
> preconfigured. Otherwise, to manually install it, you need to add value to the `Extra Parameters` section in advanced
> tab/view. add the following value `--user 99:100`. This has to happen before you start the container, otherwise it
> will
> have the old user id, and you then have to run the following command from
> terminal `chown -R 99:100 /mnt/user/appdata/watchstate`.

> [!NOTE]
> To use this container with `podman` set `docker-compose.yaml` `user` to `0:0`. it will appear to be working as root
> inside the container, but it will be mapped to the user in which the command was run under.

# Adding backend

After starting the container you should start adding your backends and to do so run the following command:

> [!NOTE]
> to get your plex token, please visit [this plex page](https://support.plex.tv/articles/204059436-finding-an-authentication-token-x-plex-token/) to know how to extract your plex token.
> For jellyfin & emby. Go to Dashboard > Advanced > API keys > then create new api keys.

```bash
$ docker exec -ti watchstate console config:add [BACKEND_NAME]
```

This command is interactive and will ask you for some questions to add your backend.

# Managing backend

To edit backend settings run

```bash
$ docker exec -ti watchstate console config:manage [BACKEND_NAME]
```

# Importing play state.

What does `Import` or what does the command `state:import` means in context of watchstate?

Import means, pulling data from the backends into the database while attempting to normalize the state.

To import your current play state from backends that have import enabled, run the following command:

```bash
$ docker exec -ti watchstate console state:import -v
```

This command will pull your play state from all your backends. To import from specific backends use
the `[-s, --select-backends]` flag which accept comma seperated list of backend names. For example,

```bash
$ docker exec -ti watchstate console state:import -v --select-backends 'home_plex,home_jellyfin' 
```

> [!NOTE]
> Now that you have imported your current play state enable the import task by adding the following environment variables to
> your `docker-compose.yaml` file `WS_CRON_IMPORT=1`. By default, we have it disabled. for more environment variables please
> refer to [Environment variables list](FAQ.md#environment-variables).

### Supported import methods

Out of the box, we support the following import methods:

* Scheduled Task. `Cron jobs that pull data from backends on a schedule.`
* On demand. `Pull data from backends on demand. By running the state:import & state:export command manually`
* Webhooks. `Receive events from backends and update the database accordingly.`

> [!NOTE]
> Even if all your backends support webhooks, you should keep import task enabled. This help keep healthy relationship. and pick up any missed events.

---

# Exporting play state

What does `export` or what does the command `state:export` means in context of watchstate?

Export means, sending data back to backends, while trying to minimize the network traffic.

To export your current play state to backends that have export enabled, run the following command

```bash
$ docker exec -ti watchstate console state:export -v
```

This command will export your current play state to all of your export enabled backends. To export to
specific backends use the `[-s, --select-backends]` flag which accept comma seperated list of backend names. For
example,

```bash
$ docker exec -ti watchstate console state:export -v --select-backends 'home_plex,home_jellyfin' 
```

> [!NOTE]
> Now that you have exported your current play state, enable the export task by adding the following environment variables to
> your `docker-compose.yaml` file `WS_CRON_EXPORT=1`. By default, we have it disabled. for more environment variables please
> refer to [Environment variables list](FAQ.md#environment-variables).

---

# FAQ

Take look at this [frequently asked questions](FAQ.md) page. to know more about this tool and how to enable webhook
support and answers to many questions.

# Social contact

If you have short or quick questions, you are free to join my [discord server](https://discord.gg/haUXHJyj6Y) and ask the question. keep in mind it's solo project, as such it might take me a bit of time to reply.

# Donate

If you feel like donating and appreciate my work, you can do so by donating to children charity. For example [Make-A-Wish](https://worldwish.org).
I Personally don't need the money, but I do appreciate the gesture. Making a child happy is the best thing you can do in this world.
