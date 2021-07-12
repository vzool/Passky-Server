# Passky-Server
## What is Passky?
Passky is simple password manager, which works on a zero trust architecture. That means only user will be able to decrypt his passwords. So users can safely store their passwords on any server. That means if a server on where all passwords are stored get hacked, hacker won't be able to decrypt passwords and data on this server will be useless for him.

**We highly suggest you to deploy Passky server via docker-compose for better security.**

[![Passky Server Installation](https://yt-embed.herokuapp.com/embed?v=NLggfKS7qP8)](https://www.youtube.com/watch?v=NLggfKS7qP8 "Click to watch!")

## Installation

### Docker compose
#### Docker (Debian & Ubuntu)
```yaml
# Install docker
curl -sSL https://get.docker.com/ | CHANNEL=stable bash
# Start docker on boot
sudo systemctl enable --now docker
# Install docker compose
sudo apt install docker-compose
```
#### Passky containers
```yaml
wget https://github.com/Rabbit-Company/Passky-Server/releases/latest/download/passky-server.tar.gz
tar -xzvf passky-server.tar.gz
cd passky-server
sudo docker-compose up -d
```
### Manually
#### Database
1. Connect to your database server (**MySQL 8.0+ required**)
2. Copy and paste sql queries from database.sql file to your database server
3. Database is now ready to be connected with API

#### API
1. Copy and paste all .php files to your website hosting provider (**PHP 8.0+ required**)
2. Open Settings.php file and edit host, database name, username and password
3. API is now ready to be connected with database

## Upgrade

### Docker compose
```yaml
# Remove old Passky server
sudo docker stop passky-php passky-mysql
sudo docker rm passky-php passky-mysql
sudo docker rmi passky-server_passky-php passky-server_passky-mysql
# Install new Passky Server
wget https://github.com/Rabbit-Company/Passky-Server/releases/latest/download/passky-server.tar.gz
tar -xzvf passky-server.tar.gz
cd passky-server
sudo docker-compose up -d
```
### Manually
#### Database
Database don't need to be upgraded.

#### API
1. Remove all .php files in public_html folder (root folder of your hosting provider)
2. Upload new ones
3. Open Settings.php file and edit host, database name, username and password
4. Upgrade is done

## Uninstall

### Docker compose
```yaml
sudo docker stop passky-php passky-mysql
sudo docker rm passky-php passky-mysql
sudo docker rmi passky-server_passky-php passky-server_passky-mysql
```
### Manually
#### Database
1. Connect to your database server
2. Execute:
```mysql
DROP DATABASE passky;
```
3. Database is now removed

#### API
1. Locate to your public_html folder (Your website root folder)
2. Delete all .php files
3. API is now removed from the server
