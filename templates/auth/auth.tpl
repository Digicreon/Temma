{*
 * Here is an example of the authentication template.
 * This template is used in two kind of situations:
 * - First when unauthenticated users want to authenticate.
 *   They will see a form, where they can enter their email addresses.
 * - When users send their email addresses, they receive an email
 *   message containing a connection link. When they click on these
 *   links, they come back to this template, but with a token in the
 *   URL. Then the template shows a link to click in order to activate
 *   the token. This two-steps check is used to avoid the consumption
 *   of the tokens by mailer systems which follows the links to check
 *   if they are not hiding a malware.
 *}
<!DOCTYPE html>
<html>
<head>
	<title>Login page</title>
</head>
<body>

	{* management of authentication statuses *}
	{if $authStatus}
		<h4>
			{if $authStatus == 'logout'}
				You have been disconnected
			{elseif $authStatus == 'email'}
				Invalid email address
			{elseif $authStatus == 'tokenSent'}
				If the email address is valid, you will receive a connection link
			{elseif $authStatus == 'badToken'}
				Expired connection token
			{else}
				An error occurred
			{/if}
		</h4>
	{/if}

	<h3>Sign in</h3>
	{if $token}
		{* a token has been given *}
		<p>To sign in, click on this link:</p>
		<p><a href="/auth/check/{$token|escape}">Sign in</a></p>
	{else}
		{* authentication form *}
		<p>Please enter you email address</p>
		<form method="post" action="/auth/authentication">
			<input type="email" name="email" placeholder="somebody@somewhere.com" />
			<input type="submit" value="Send" />
		</form>
		<p><small>
			By sending your email address, you accept the terms of use.<br />
			Authentication requires the deposit of a cookie on your browser.
		</small></p>
	{/if}

</body>
</html>
