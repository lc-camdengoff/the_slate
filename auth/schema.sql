-- The Slate — accounts schema.
--
-- Applied automatically by db.php on first request; see slate_migrate().
-- Every statement must be safe to re-run.

CREATE TABLE IF NOT EXISTS users (
    id            bigserial PRIMARY KEY,
    username      text        NOT NULL,
    -- lower(username), so "Camden" and "camden" cannot both exist
    username_ci   text        NOT NULL UNIQUE,
    display_name  text        NOT NULL,
    password_hash text        NOT NULL,
    is_admin      boolean     NOT NULL DEFAULT false,
    is_active     boolean     NOT NULL DEFAULT true,
    created_at    timestamptz NOT NULL DEFAULT now(),
    last_login_at timestamptz,
    -- Collected at signup but never verified: mail to @life.church is held in
    -- quarantine inbound, so a verification link would not arrive. The invite
    -- code is what keeps an unverified address reasonable. The Cage refuses a
    -- sign-in from an account without one, since a username cannot receive an
    -- overdue notice. See auth/email.php.
    email         text,
    -- Contact details an admin keeps, mostly brought over from Cheqroom.
    -- The Cage reads phone and department through verify.php.
    phone         text,
    department    text,
    notes         text
);
-- An account made by an admin or an import has no password until its owner
-- redeems the setup code they were given: password_hash is '' until then,
-- which no password_verify() call can match.

-- Addresses are matched lowercased, so Jordan.West@ and jordan.west@ are one
-- mailbox. This index is also what fm_set_email() relies on to catch a
-- duplicate as 23505 rather than silently writing it.
CREATE UNIQUE INDEX IF NOT EXISTS users_email_lower_idx
    ON users (lower(email)) WHERE email IS NOT NULL;

-- What each person may do in each tool. A missing row means the tool's
-- default role from tools.php, so a new tool or a new signup just works;
-- 'none' is an explicit "no access". Roles are the tool's own vocabulary,
-- listed in tools.php — the database does not second-guess them.
CREATE TABLE IF NOT EXISTS user_tool_roles (
    user_id bigint NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    tool    text   NOT NULL,
    role    text   NOT NULL,
    PRIMARY KEY (user_id, tool)
);

-- Shared signup codes. Stored in the clear on purpose: an admin has to be able
-- to read one back to pass it to the team, and a code only grants the ability
-- to create an account — anyone who can read this table can already read the
-- password hashes, so hashing it would buy nothing.
CREATE TABLE IF NOT EXISTS invite_codes (
    id         bigserial PRIMARY KEY,
    code       text        NOT NULL UNIQUE,
    label      text        NOT NULL DEFAULT '',
    max_uses   integer,                          -- NULL = unlimited
    uses       integer     NOT NULL DEFAULT 0,
    expires_at timestamptz,                      -- NULL = never
    is_active  boolean     NOT NULL DEFAULT true,
    created_by bigint      REFERENCES users(id) ON DELETE SET NULL,
    created_at timestamptz NOT NULL DEFAULT now()
);

-- Sessions live here rather than in PHP's own session files: on shared hosting
-- those sit in a world-ish /tmp, and keeping them in the database means logout
-- and "sign out everywhere" actually revoke. Only the hash of the cookie token
-- is stored, so a database leak does not hand over live sessions.
CREATE TABLE IF NOT EXISTS sessions (
    token_hash   text        PRIMARY KEY,
    user_id      bigint      NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    created_at   timestamptz NOT NULL DEFAULT now(),
    last_seen_at timestamptz NOT NULL DEFAULT now(),
    expires_at   timestamptz NOT NULL,
    ip           text,
    user_agent   text
);
CREATE INDEX IF NOT EXISTS sessions_user_idx ON sessions (user_id);
CREATE INDEX IF NOT EXISTS sessions_expiry_idx ON sessions (expires_at);

-- There is no outbound mail on this host, so a reset is an admin handing a
-- one-time code to someone in person or over chat.
CREATE TABLE IF NOT EXISTS password_resets (
    id         bigserial PRIMARY KEY,
    user_id    bigint      NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    code_hash  text        NOT NULL,
    expires_at timestamptz NOT NULL,
    used_at    timestamptz,
    created_by bigint      REFERENCES users(id) ON DELETE SET NULL,
    created_at timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX IF NOT EXISTS password_resets_user_idx ON password_resets (user_id);

-- Throttling. The invite code is the only thing standing between the internet
-- and a new account, so signup attempts are rate limited as tightly as logins.
CREATE TABLE IF NOT EXISTS auth_attempts (
    id      bigserial PRIMARY KEY,
    kind    text        NOT NULL,           -- login | signup | reset
    ip      text        NOT NULL,
    subject text        NOT NULL DEFAULT '', -- username or code attempted
    ok      boolean     NOT NULL,
    at      timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX IF NOT EXISTS auth_attempts_ip_idx ON auth_attempts (kind, ip, at);
CREATE INDEX IF NOT EXISTS auth_attempts_subject_idx ON auth_attempts (kind, subject, at);
