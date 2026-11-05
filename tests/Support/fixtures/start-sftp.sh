#!/usr/bin/env bash
# An SFTP server for the connector tests, on 127.0.0.1:2222, on a CI runner. It listens on loopback
# only, for one throwaway user, and its keys and password live as long as the job. What the tests need
# to reach it goes into $GITHUB_ENV.
set -euo pipefail

dir=$(mktemp -d)
password=$(openssl rand -hex 16)

sudo apt-get install -y -qq openssh-server >/dev/null

id feedtest >/dev/null 2>&1 || sudo useradd --create-home --shell /usr/sbin/nologin feedtest
printf 'feedtest:%s\n' "$password" | sudo chpasswd

ssh-keygen -q -t ed25519 -N '' -f "$dir/host_key"
ssh-keygen -q -t ed25519 -N '' -f "$dir/client_key"

sudo install -d -o feedtest -g feedtest -m 755 /home/feedtest/out
printf 'sku;title\r\nA-1;H\xc3\xa4mmer\r\n' | sudo -u feedtest tee /home/feedtest/out/feed.csv >/dev/null
head -c 5000 /dev/zero | tr '\0' 'x' | sudo -u feedtest tee /home/feedtest/out/large.csv >/dev/null

sudo install -d -o feedtest -g feedtest -m 700 /home/feedtest/.ssh
sudo install -o feedtest -g feedtest -m 600 "$dir/client_key.pub" /home/feedtest/.ssh/authorized_keys

cat >"$dir/sshd_config" <<EOF
Port 2222
ListenAddress 127.0.0.1
HostKey $dir/host_key
PidFile $dir/sshd.pid
PasswordAuthentication yes
PubkeyAuthentication yes
UsePAM no
AllowUsers feedtest
Subsystem sftp internal-sftp
EOF

sudo mkdir -p /run/sshd
sudo chown root:root "$dir/host_key"
sudo /usr/sbin/sshd -f "$dir/sshd_config"

{
  echo "FEEDGENIX_TEST_SFTP=127.0.0.1:2222"
  echo "FEEDGENIX_TEST_SFTP_PASSWORD=$password"
  echo "FEEDGENIX_TEST_SFTP_KEY=$dir/client_key"
  echo "FEEDGENIX_TEST_SFTP_HOST_KEY=$(ssh-keygen -lf "$dir/host_key.pub" | awk '{ print $2 }')"
} >>"$GITHUB_ENV"
