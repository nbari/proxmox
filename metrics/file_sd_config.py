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
    """Fetch running VMs with their IPs from a Proxmox node."""
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
                # Get the VM name
                vm_name = vm.get("name", "")
                if not vm_name:
                    vm_name = f"VM {vm['vmid']}"

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
                                    # Skip localhost addresses
                                    ip_address = addr["ip-address"]
                                    if not ip_address.startswith("127."):
                                        instances.append(
                                            {
                                                "targets": [f"{ip_address}:9100"],
                                                "labels": {
                                                    "job": "node_exporter",
                                                    "name": vm_name,
                                                    "node": node,
                                                },
                                            }
                                        )
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

        # Create directory if it doesn't exist
        os.makedirs(os.path.dirname(OUTPUT_FILE), exist_ok=True)

        with open(OUTPUT_FILE, "w") as f:
            json.dump(all_instances, f, indent=2)

        logger.info(f"Configuration file generated: {OUTPUT_FILE}")
    except Exception as e:
        logger.error(f"Error in main function: {str(e)}")
        sys.exit(1)


if __name__ == "__main__":
    main()
