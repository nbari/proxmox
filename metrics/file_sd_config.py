import ipaddress
import json
import logging
import os
import sys

import requests

# Configure logging
logging.basicConfig(
    level=logging.INFO,
    format="%(asctime)s - %(levelname)s - %(message)s",
    handlers=[logging.StreamHandler(sys.stdout)],
)
logger = logging.getLogger("proxmox_sd")

# Load configuration from environment variables
PROXMOX_HOST = os.getenv("PROXMOX_HOST", "https://your-proxmox-host:8006")
TOKEN_ID = os.getenv("PROXMOX_TOKEN_ID", "metrics")
TOKEN_SECRET = os.getenv("PROXMOX_TOKEN_SECRET", "your-secret-token")
VERIFY_SSL = os.getenv("PROXMOX_VERIFY_SSL", "true").lower() == "true"
OUTPUT_FILE = os.getenv("PROXMOX_SD_FILE", "/etc/prometheus/proxmox_sd.json")
TIMEOUT = int(os.getenv("REQUEST_TIMEOUT", "10"))  # Request timeout in seconds
TARGET_NETWORK = os.getenv("TARGET_NETWORK", "10.0.0.0/24")

# Convert to an ipaddress object
try:
    TARGET_NETWORK_OBJ = ipaddress.ip_network(TARGET_NETWORK, strict=False)
except ValueError:
    logger.error(f"Invalid TARGET_NETWORK CIDR: {TARGET_NETWORK}")
    sys.exit(1)

# Headers for authentication
HEADERS = {"Authorization": f"PVEAPIToken=root@pam!{TOKEN_ID}={TOKEN_SECRET}"}


def get_nodes():
    """Fetch all active nodes from the Proxmox cluster."""
    url = f"{PROXMOX_HOST}/api2/json/nodes"
    try:
        response = requests.get(
            url, headers=HEADERS, verify=VERIFY_SSL, timeout=TIMEOUT
        )
        response.raise_for_status()
        nodes = response.json().get("data", [])
        return [node["node"] for node in nodes if node["status"] == "online"]
    except Exception as e:
        logger.error(f"Failed to fetch nodes: {str(e)}")
        return []


def get_vm_ips(node):
    """Fetch running QEMU VMs with their IPs from a Proxmox node."""
    instances = []
    url = f"{PROXMOX_HOST}/api2/json/nodes/{node}/qemu"

    try:
        response = requests.get(
            url, headers=HEADERS, verify=VERIFY_SSL, timeout=TIMEOUT
        )
        response.raise_for_status()
        vms = response.json().get("data", [])

        for vm in vms:
            if vm["status"] == "running":
                vm_name = vm.get("name", f"VM-{vm['vmid']}")
                vmid = vm["vmid"]

                try:
                    net_url = f"{PROXMOX_HOST}/api2/json/nodes/{node}/qemu/{vmid}/agent/network-get-interfaces"
                    net_response = requests.get(
                        net_url, headers=HEADERS, verify=VERIFY_SSL, timeout=TIMEOUT
                    )

                    if net_response.status_code == 200:
                        net_data = net_response.json().get("data", {}).get("result", [])
                        for iface in net_data:
                            for addr in iface.get("ip-addresses", []):
                                if addr["ip-address-type"] == "ipv4":
                                    ip_address = addr["ip-address"]

                                    # Validate and filter IP address
                                    if ip_address := ipaddress.ip_address(ip_address):

                                        # Skip loopback (127.0.0.0/8), link-local (169.254.0.0/16), and unspecified (0.0.0.0)
                                        if not (
                                            ip_address.is_loopback
                                            or ip_address.is_link_local
                                            or ip_address.is_unspecified
                                        ):

                                            # Check if the IP belongs to the defined target network
                                            if ip_address in TARGET_NETWORK_OBJ:
                                                instances.append(
                                                    {
                                                        "targets": [
                                                            f"{ip_address}:9100"
                                                        ],
                                                        "labels": {
                                                            "job": "node_exporter",
                                                            "name": vm_name,
                                                            "node": node,
                                                        },
                                                    }
                                                )
                                            else:
                                                logger.info(
                                                    f"Skipping {ip_address}, not in {TARGET_NETWORK}"
                                                )  # Log only if outside the target network

                except Exception as e:
                    logger.warning(
                        f"Could not fetch network info for QEMU {vmid} on {node}: {str(e)}"
                    )

    except Exception as e:
        logger.error(f"Failed to fetch QEMU VMs for node {node}: {str(e)}")

    return instances


def main():
    """Main function to generate Prometheus service discovery file."""
    try:
        all_instances = []
        nodes = get_nodes()

        if not nodes:
            logger.error("No active Proxmox nodes found.")
            sys.exit(1)

        for node in nodes:
            node_instances = get_vm_ips(node)
            all_instances.extend(node_instances)

        # Validate JSON before writing
        json_data = json.dumps(all_instances, indent=2)

        # Create directory if it doesn't exist
        os.makedirs(os.path.dirname(OUTPUT_FILE), exist_ok=True)

        # Write to temp file first (atomic write)
        tmp_file = OUTPUT_FILE + ".tmp"
        with open(tmp_file, "w") as f:
            f.write(json_data)

        # Rename temp file to final output
        os.rename(tmp_file, OUTPUT_FILE)

        logger.info(f"Configuration file generated: {OUTPUT_FILE}")
    except Exception as e:
        logger.error(f"Error in main function: {str(e)}")
        sys.exit(1)


if __name__ == "__main__":
    main()
