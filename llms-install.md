# temma

> Temma is a PHP MVC framework (PHP 8.3+) for building websites, APIs and command-line
> tools. Projects are created from a Composer skeleton. The framework ships with AI
> skills that are installed into each project and guide feature development.
> Documentation: https://www.temma.net

I want you to create a new Temma project for me. Execute the steps below autonomously:
ask me only the questions listed in step 2, then proceed without asking for further
confirmation. If a Temma project already exists in the current directory (there is an
`etc/temma.php` file), skip to step 7.

OBJECTIVE: Create a working Temma project on this machine, configured for my needs
(project type, web server, database), ready for feature development with the bundled
AI skills.

DONE WHEN: The skeleton home page responds locally with HTTP 200, the configured
database (if any) is reachable, and the `temma-*` skills are present in the project.

TODO:
- [ ] Check prerequisites (PHP 8.3+, Composer)
- [ ] Ask the setup questions
- [ ] Create the project with `composer create-project`
- [ ] Configure the database and verify the connection
- [ ] Serve the site and verify the home page
- [ ] Read the `temma-site-guide` skill and ask what to build first

## 1. Check prerequisites

You need PHP 8.3 or later (CLI) with the `mbstring` extension, and Composer:

```
php -v
php -m | grep -i mbstring
composer --version
```

If something is missing, install it with the system package manager. Ask the user
before running anything with elevated privileges:

- Debian/Ubuntu: `sudo apt install php-cli php-mbstring composer`
- Fedora: `sudo dnf install php-cli php-mbstring composer`
- macOS (Homebrew): `brew install php composer`
- Windows: install PHP from https://windows.php.net/download/ and Composer from
  https://getcomposer.org/download/

## 2. Ask the setup questions

Before asking, detect which database engines are usable on this machine: a reachable
MySQL/MariaDB server, a reachable PostgreSQL server, the `pdo_sqlite` PHP extension.
Then ask the user all the questions in a single message:

1. Project type: a website (HTML pages, Smarty templates) or an API (JSON responses,
   versioned endpoints)?
2. Project name (used as the directory name).
3. How to serve it locally:
   - development server (PHP built-in server, nothing to install), a good starting
     point in every case;
   - Apache: the configuration will be generated from `etc/apache.conf`;
   - Nginx: the configuration will be generated from `etc/nginx.conf`.
4. Database, offering only the engines that can work here, in this order of
   preference: MySQL (recommended), PostgreSQL, SQLite (no server needed), or no
   database at all.

## 3. Create the project

Run the command matching the chosen project type:

```
composer create-project digicreon/temma-project-web <project_name>    # website
composer create-project digicreon/temma-project-api <project_name>    # API
```

This installs the framework (`digicreon/temma-lib` package), sets file permissions,
and installs the Temma AI skills into the project as `.claude/skills/temma-*` (for
Claude Code) and `.agents/skills/temma-*` (for other coding agents). All the project
configuration lives in `etc/temma.php`.

## 4. Configure the database

Skip this step if the user chose no database; in that case remove the `db` entry from
`dataSources` in `etc/temma.php`.

First make sure the matching PDO extension is installed (install it as in step 1):
`pdo_mysql` (package `php-mysql`), `pdo_pgsql` (package `php-pgsql`) or `pdo_sqlite`
(package `php-sqlite3`).

Create the database. Ask the user for administration credentials, never guess them:

```
# MySQL/MariaDB
mysql -u root -p -e "CREATE DATABASE <dbname> CHARACTER SET utf8mb4; CREATE USER '<user>'@'localhost' IDENTIFIED WITH mysql_native_password BY '<password>'; GRANT ALL PRIVILEGES ON <dbname>.* TO '<user>'@'localhost';"
# PostgreSQL
sudo -u postgres psql -c "CREATE USER <user> PASSWORD '<password>'; CREATE DATABASE <dbname> OWNER <user>;"
# SQLite: nothing to create, the file is created on first use (use var/<dbname>.sq3)
```

Then set the connection string in `etc/temma.php`, key `application.dataSources.db`:

- MySQL: `mysql://<user>:<password>@localhost/<dbname>`
- PostgreSQL: `pgsql://<user>:<password>@localhost/<dbname>`
- SQLite: `sqlite:<absolute path to the project>/var/<dbname>.sq3`

Verify the connection with a quick PDO test, for example:

```
php -r "new PDO('mysql:host=localhost;dbname=<dbname>', '<user>', '<password>'); echo 'OK';"
```

## 5. Serve the site

In all cases the document root is the project's `www/` directory, and every request
that does not match an existing file must be rewritten to `www/index.php`. The `log/`
and `tmp/` directories must be writable by the user running the server.

### Development server

Run, from the project root:

```
bin/comma Temma/serve
```

It starts the framework's development server (based on the PHP built-in web server)
on port 8000, serving static files from `www/` and routing everything else to the
front controller. Use `--port=<port>` to change the port. It runs in the foreground,
so start it in the background or in a separate terminal.

### Apache

Adapt `etc/apache.conf`: replace `mysite.com` with the real host name and the
document root with the absolute path of the project's `www/` directory. Enable
`mod_rewrite` (`a2enmod rewrite`), install the file into the server configuration
(e.g. `/etc/apache2/sites-available/` then `a2ensite`) and reload Apache. Ask the
user before writing to system directories.

### Nginx

Adapt `etc/nginx.conf` the same way, and adjust the `fastcgi_pass` PHP-FPM socket
path to the installed PHP version. Install the file into the server configuration
(e.g. `/etc/nginx/sites-available/` plus a symlink) and reload Nginx. Ask the user
before writing to system directories.

## 6. Verify

Request the home page (adapt the URL to the chosen server):

```
curl -i http://localhost:8000/
```

It must answer HTTP 200 with the skeleton home page. If it does not, check the
`log/temma.log` file in the project.

## 7. Develop with the AI skills

The project contains the Temma AI skills, one per topic (controllers, views, DAOs,
validation, authentication, API...). They are the reference for everything that
follows; prefer them over guessing framework behavior.

- Claude Code: `.claude/skills/temma-*` (available as regular skills);
- other agents: read them as Markdown files in `.agents/skills/temma-*/SKILL.md`.

Read the `temma-site-guide` skill now: it is the step-by-step guide for building the
site or the API, and it points to the other skills at each step.

EXECUTE NOW: Complete the TODO list above until the skeleton home page responds
locally and the database (if any) is reachable. Then read the `temma-site-guide`
skill, tell the user their project is ready, and ask what to build first.

---

Full documentation: https://www.temma.net/en/documentation
