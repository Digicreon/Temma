---
name: temma-email
description: Send emails from a Temma PHP framework project, through the local MTA or a configurable SMTP relay (Gmail, Mailgun, or any SMTP server). Use when sending plain-text or HTML emails, attachments, templated emails, or when configuring the email transport in x-email.
license: MIT
---

# Sending emails in Temma

The `\Temma\Utils\Email` helper builds and sends emails. Always prefer the
**object-oriented methods** (obtained through the dependency injection component): they
honor the `x-email` configuration (transport, kill-switch, domain filtering, forced
recipients), unlike the static `simpleMail()`/`fullMail()` which always go through PHP's
`mail()`.

## Sending

```php
$email = $this->_loader['\Temma\Utils\Email'];

// plain text
$email->textMail('Sender <from@dom.com>', 'to@dom.com', 'Subject', 'Message body');

// HTML (+ optional text alternative and attachments)
$email->mimeMail('from@dom.com', ['a@x.com', 'b@y.com'], 'Subject',
                 '<h1>Hello</h1>', 'Text alternative',
                 [['filename' => 'report.pdf', 'mimetype' => 'application/pdf', 'data' => $binary]]);

// from Smarty templates (paths under templates/, or absolute)
$email->templatedMail('from@dom.com', 'to@dom.com', 'Subject',
                      'mail/welcome_html.tpl', 'mail/welcome_text.tpl',
                      ['name' => $userName]);
```

Common optional parameters: `$cc`, `$bcc` (string or list), `$unsubscribe`
(`List-Unsubscribe` header content), `$envelopeSender` (SMTP envelope sender / return
path, used for `MAIL FROM`).

`templatedMail()` renders its templates with the `temma-view` machinery: the HTML
template follows the configured HTML auto-escaping (escaped by default), the text
template is never auto-escaped.

## Configuration (`x-email` in `etc/temma.php`)

```php
'x-email' => [
	'disabled'       => true,                        // kill-switch (test platforms)
	'allowedDomains' => ['temma.net', 'mydom.com'],  // drop recipients outside these domains
	'cc'             => 'archive@mydom.com',         // added to every message
	'bcc'            => ['boss@mydom.com'],
	'envelopeSender' => 'bounces@mydom.com',
	'transport'      => 'smtp+tls://user:pass@smtp.gmail.com:587',
],
```

`disabled` and `allowedDomains` are the right way to keep test environments from
emailing real users. Runtime equivalents: `enable(bool)`, `setAllowedDomains()`,
`setCc()`, `setBcc()`, `setEnvelopeSender()`, `setTransport()`.

## Transport

Without configuration, messages go through PHP's `mail()` (local MTA: sendmail, Postfix,
Exim). To send through an **SMTP relay** instead, set a transport; the `transport` key
(and the `setTransport()` method) accepts:

- the **name of a data source** declared in `dataSources`
  (`'transport' => 'mail'` with `'mail' => 'smtp+tls://...'`);
- a **DSN** directly: `smtp://host:25` (cleartext, e.g. local relay),
  `smtp+tls://user:pass@host:587` (STARTTLS), `smtps://user:pass@host:465` (implicit
  TLS); optional query parameters `helo`, `timeout`, `verify`;
- an **array of SMTP parameters** (handy when the password contains special
  characters): `['host' => ..., 'port' => 587, 'security' => 'starttls', 'user' => ...,
  'password' => ...]`.

A string is treated as a DSN when it contains `://`, as a data source name otherwise.

The transport can actually be **any data source supporting `set()`**: an SMTP source
delivers, a `File`/`S3` source archives messages, a queue source (SQS, Beanstalk) defers
them, `dummy://` discards them. The helper hands over
`['from' => ..., 'recipients' => [...], 'message' => <raw RFC 5322>]`.

## Operational notes

- SPF/DKIM/DMARC are DNS and relay-side concerns; the application-side levers are the
  envelope sender and the `From` header. When relaying through Gmail & co, DKIM signing
  is handled by the relay.
- Sending is slow (network): for bulk or user-facing flows, consider deferring through a
  queue transport or the `temma-asynk` skill.
- The passwordless authentication system (`temma-auth` skill) sends its magic links
  through this helper: configure the transport if the server has no local MTA.

## Further reading

- https://www.temma.net/en/documentation/helper-email
- https://www.temma.net/en/documentation/datasource-smtp
