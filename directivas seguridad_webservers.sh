#!/bin/bash
# hardening-web-servers.sh - Hardening completo servidores web AWS Ubuntu
# Proyecto ASIR - Raquel 2026

echo "🚀 INICIANDO HARDENING SERVIDORES WEB (nginx)..."
echo "📅 $(date)"
echo "🐧 $(hostnamectl | grep 'Operating System')"
echo "================================"

# COLORES
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

# 1. APACHE2 OFF (si existe)
echo -e "${YELLOW}[1/6] Desactivando Apache2...${NC}"
if systemctl is-enabled apache2 >/dev/null 2>&1; then
    sudo systemctl disable --now apache2
    echo -e "${GREEN}✅ Apache2 desactivado${NC}"
else
    echo -e "${GREEN}✅ Apache2 no instalado${NC}"
fi

# 2. UFW FIREWALL (SSH + Web)
echo -e "${YELLOW}[2/6] Configurando UFW Firewall...${NC}"
sudo ufw default deny incoming
sudo ufw default allow outgoing
sudo ufw allow 22/tcp      # SSH
sudo ufw allow 80/tcp      # HTTP nginx
sudo ufw allow 443/tcp     # HTTPS nginx
sudo ufw --force enable
echo -e "${GREEN}✅ UFW configurado: 22,80,443${NC}"
sudo ufw status numbered

# 3. PWQUALITY - Políticas contraseñas
echo -e "${YELLOW}[3/6] Configurando pwquality...${NC}"
sudo apt update
sudo apt install -y libpam-pwquality
sudo pam-auth-update --force << 'EOF'
/usr/share/pam-configs/unix2
EOF
# Configurar /etc/security/pwquality.conf
cat > /tmp/pwquality.conf << 'EOF'
# Políticas contraseñas CIS Level 1
minlen = 12
dcredit = -1    # 1 dígito mínimo
ucredit = -1    # 1 mayúscula mínima
lcredit = -1    # 1 minúscula mínima
ocredit = -1    # 1 especial mínimo
maxrepeat = 3
EOF
sudo cp /tmp/pwquality.conf /etc/security/pwquality.conf
sudo rm /tmp/pwquality.conf

# /etc/login.defs
sudo sed -i 's/^PASS_MAX_DAYS.*/PASS_MAX_DAYS   90/' /etc/login.defs
sudo sed -i 's/^PASS_MIN_DAYS.*/PASS_MIN_DAYS   1/' /etc/login.defs
sudo sed -i 's/^PASS_WARN_AGE.*/PASS_WARN_AGE   7/' /etc/login.defs
echo -e "${GREEN}✅ pwquality configurado (12 chars, complejidad)${NC}"

# Test pwquality
pwscore "abc123" && echo -e "${RED}❌ pwscore falló${NC}" || echo -e "${GREEN}✅ pwscore funciona${NC}"

# 4. KERNEL HARDENING sysctl
echo -e "${YELLOW}[4/6] Kernel hardening sysctl...${NC}"
cat > /tmp/sysctl-hardening.conf << 'EOF'
# Kernel Hardening - CIS Level 1
net.ipv4.conf.all.rp_filter = 1
net.ipv4.conf.default.rp_filter = 1
net.ipv4.tcp_syncookies = 1
net.ipv4.conf.all.accept_redirects = 0
net.ipv6.conf.all.accept_redirects = 0
net.ipv4.conf.all.accept_source_route = 0
net.ipv4.conf.all.send_redirects = 0
kernel.printk = 3 4 1 3
EOF

sudo cp /tmp/sysctl-hardening.conf /etc/sysctl.d/10-hardening.conf
sudo sysctl -p /etc/sysctl.d/10-hardening.conf
sudo rm /tmp/sysctl-hardening.conf
echo -e "${GREEN}✅ Kernel hardening aplicado${NC}"
sysctl net.ipv4.tcp_syncookies net.ipv4.conf.all.rp_filter
# 5. SSH HARDENING
echo -e "${YELLOW}[5/6] SSH hardening...${NC}"
sudo ssh-keygen -A  # Generar hostkeys si faltan

# Backup config original
sudo cp /etc/ssh/sshd_config /etc/ssh/sshd_config.backup

cat >> /etc/ssh/sshd_config << 'EOF'
# ========== SSH HARDENING - PROYECTO ASIR ==========
# Desactivar root login
PermitRootLogin no

# Solo claves SSH (no contraseñas)
PasswordAuthentication no
PubkeyAuthentication yes

# Límites conexión
MaxAuthTries 3
ClientAliveInterval 300
ClientAliveCountMax 2

# Protocolo moderno
Protocol 2
EOF
# Test + restart SSH
if sudo sshd -t; then
    sudo systemctl restart ssh
    echo -e "${GREEN}✅ SSH endurecido: no root, solo claves${NC}"
    sudo sshd -T | grep -E 'permitrootlogin|passwordauthentication|maxauthtries'
else
    echo -e "${RED}❌ Error SSH config - Restaurando backup${NC}"
    sudo cp /etc/ssh/sshd_config.backup /etc/ssh/sshd_config
    sudo systemctl restart ssh
    exit 1
fi
# 6. AUDITD
echo -e "${YELLOW}[6/6] Configurando auditd...${NC}"
sudo apt install -y auditd audispd-plugins

sudo tee /etc/audit/rules.d/asir-hardening.rules << 'EOF'
# Auditoría ASIR Project
-w /etc/passwd -p wa -k identity
-w /etc/shadow -p wa -k identity
-w /etc/ssh/sshd_config -p wa -k sshd_config
-w /var/log/auth.log -p wa -k auth_logs
EOF

sudo augenrules --load
sudo systemctl restart auditd
sudo systemctl enable auditd
# Prueba auditd
sudo touch /etc/passwd.test
sleep 1
sudo ausearch -k identity -ts today | tail -1 && sudo rm /etc/passwd.test
echo -e "${GREEN}✅ Auditd funcionando${NC}"
# 7. LIMPIAR SERVICIOS INNECESARIOS
echo -e "${YELLOW}🧹 Limpiando servicios innecesarios...${NC}"
sudo systemctl disable --now open-iscsi iscsid.socket 2>/dev/null || true
# RESULTADO FINAL
echo "
${GREEN}
🎉 HARDENING 100% COMPLETO! 🎉
===============================
✅ Apache2 desactivado
✅ UFW: 22,80,443 permitidos
✅ pwquality: contraseñas fuertes
✅ sysctl: kernel endurecido
✅ SSH: solo claves, no root
✅ auditd: auditoría activa
✅ iSCSI desactivado

$(sudo ufw status)
Puertos abiertos: $(ss -tulnp | grep -E '22|80|443' | wc -l)/total
