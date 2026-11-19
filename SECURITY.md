# Security Policy

If you find a security vulnerability, **please don't open a public issue**. Report it privately through GitHub's **Security → Report a vulnerability** on this repository. We'll respond as soon as possible.

## Advice for merchants
- In direct mode, store your private key file **outside the web root** and give it restrictive file permissions.
- Use the EFRIS **test** environment until you have completed UAT with URA.
- Leave **Debug log** off in production unless you're troubleshooting. Logs contain invoice data.
