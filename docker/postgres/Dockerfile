# PIM database image — postgres:16-alpine + pgBackRest production setup (#106 / 0.11.11).
#
# Hosts both the PostgreSQL server and the pgBackRest CLI so:
#   - `archive_command` ships WAL segments asynchronously to MinIO from
#     inside the server process (async spool worker, no SSH, no volume
#     gymnastics).
#   - Cron runs the production schedule:
#       * Sundays 02:00 — `--type=full`
#       * Mon-Sat 02:00 — `--type=diff`
#       * Saturdays 03:30 — automated restore test (cluster-clone smoke).
#   - The stanza-create + first full backup run in the background once
#     postgres is ready, so the repo is initialised the moment the stack is up.
#
# See docs/runbook/restore.md (PITR) + docs/runbook/disaster-recovery.md
# (incident response) for the operator-facing runbooks.

FROM postgres:16-alpine

# pgBackRest pulls in libxml2/libssh; dcron is busybox-based and tiny; tini
# reaps zombies left behind by the background init helper.
RUN apk add --no-cache pgbackrest dcron tini bash

# Filesystem layout owned by the postgres user (UID 70 in alpine images).
RUN install -d -m 0750 -o postgres -g postgres /etc/pgbackrest \
 && install -d -m 0750 -o postgres -g postgres /var/log/pgbackrest \
 && install -d -m 0750 -o postgres -g postgres /var/spool/pgbackrest \
 && install -d -m 0755 -o postgres -g postgres /var/lib/pgbackrest

COPY pgbackrest.conf /etc/pgbackrest/pgbackrest.conf
RUN chown postgres:postgres /etc/pgbackrest/pgbackrest.conf \
 && chmod 0640 /etc/pgbackrest/pgbackrest.conf

COPY pim-init-backup.sh /usr/local/bin/pim-init-backup.sh
COPY pim-init-app-role.sh /usr/local/bin/pim-init-app-role.sh
COPY pim-init-query-stats.sh /usr/local/bin/pim-init-query-stats.sh
COPY pim-cron.sh /usr/local/bin/pim-cron.sh
COPY pim-restore-test.sh /usr/local/bin/pim-restore-test.sh
COPY pim-render-pgbackrest-conf.sh /usr/local/bin/pim-render-pgbackrest-conf.sh
COPY start-pim.sh /usr/local/bin/start-pim.sh
RUN chmod 0755 /usr/local/bin/pim-init-backup.sh \
                /usr/local/bin/pim-render-pgbackrest-conf.sh \
                /usr/local/bin/pim-init-app-role.sh \
                /usr/local/bin/pim-init-query-stats.sh \
                /usr/local/bin/pim-cron.sh \
                /usr/local/bin/pim-restore-test.sh \
                /usr/local/bin/start-pim.sh

# Production schedule (#106 / 0.11.11):
#   * Sundays    02:00 — full backup
#   * Mon-Sat    02:00 — differential backup
#   * Saturdays  03:30 — automated restore test (post-full, before next diff)
#
# WAL is archive-async pushed by archive_command on every segment switch
# (typically every few seconds under load) — no cron entry needed.
RUN printf '%s\n' \
    '0 2 * * 0 /usr/local/bin/pim-cron.sh full >> /var/log/pgbackrest/cron.log 2>&1' \
    '0 2 * * 1-6 /usr/local/bin/pim-cron.sh diff >> /var/log/pgbackrest/cron.log 2>&1' \
    '30 3 * * 6 /usr/local/bin/pim-restore-test.sh >> /var/log/pgbackrest/restore-test.log 2>&1' \
        > /etc/crontabs/postgres \
 && chmod 0600 /etc/crontabs/postgres \
 && chown postgres:postgres /etc/crontabs/postgres

ENTRYPOINT ["/sbin/tini", "--", "/usr/local/bin/start-pim.sh"]
CMD ["postgres"]
