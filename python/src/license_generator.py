import secrets
import base64
import secrets
import base64
import mysql.connector
from mysql.connector import Error
import os
import json
import re
import string
from typing import List
from datetime import datetime
import sys


class Colors:
    BLUE = '\033[34;1m'
    GREEN = '\033[32;1m'
    YELLOW = '\033[33;1m'
    RED = '\033[31;1m'
    CYAN = '\033[36;1m'
    MAGENTA = '\033[35;1m'
    WHITE = '\033[37;1m'
    RESET = '\033[0m'
    BOLD = '\033[1m'
    DIM = '\033[2m'


def load_config(config_file: str = 'config.json') -> dict:
    candidates = []
    candidates.append(os.path.abspath(config_file))
    candidates.append(os.path.abspath(os.path.join(os.path.dirname(__file__), '..', config_file)))
    candidates.append(os.path.abspath(os.path.join(os.path.dirname(__file__), config_file)))
    candidates.append(os.path.abspath(os.path.join(os.getcwd(), config_file)))

    tried = []
    for path in candidates:
        if not path:
            continue
        tried.append(path)
        try:
            with open(path, 'r', encoding='utf-8') as f:
                return json.load(f)
        except FileNotFoundError:
            continue
        except json.JSONDecodeError:
            print(f"{Colors.RED}Error: {path} is not valid JSON!{Colors.RESET}")
            sys.exit(1)

    print(f"{Colors.RED}Error: config.json not found!{Colors.RESET}")
    print("Searched locations:")
    for p in tried:
        print(f" - {p}")
    print("Please create a config.json file with your settings.")
    sys.exit(1)


CONFIG = load_config()
DB_CONFIG = CONFIG['database']
TABLE_NAME = CONFIG['table']['name']
PRODUCT_NAME = CONFIG.get('product', {}).get('name', '')
KEY_LENGTH = CONFIG['license']['key_length']


class LicenseKeyGenerator:
    @staticmethod
    def generate_key(length: int = KEY_LENGTH) -> str:
        key = ""
        while len(key) < length:
            random_bytes = secrets.token_bytes(length)
            b64_string = base64.b64encode(random_bytes).decode('utf-8')
            alphanumeric = ''.join(char for char in b64_string if char.isalnum())
            key += alphanumeric
        return key[:length]

    @staticmethod
    def generate_multiple_keys(count: int, length: int = KEY_LENGTH) -> List[str]:
        keys = set()
        while len(keys) < count:
            keys.add(LicenseKeyGenerator.generate_key(length))
        return list(keys)


class LicenseDatabase:
    def __init__(self, config: dict):
        self.config = config
        self.connection = None

    def connect(self) -> bool:
        try:
            self.connection = mysql.connector.connect(**self.config)
            if self.connection.is_connected():
                return True
        except Error as e:
            print(f"{Colors.RED}Error connecting to MariaDB: {e}{Colors.RESET}")
            return False

    def disconnect(self):
        if self.connection and self.connection.is_connected():
            self.connection.close()

    def database_exists(self) -> bool:
        try:
            temp_config = self.config.copy()
            db_name = temp_config.pop('database')
            temp_conn = mysql.connector.connect(**temp_config)
            cursor = temp_conn.cursor()
            cursor.execute(f"SHOW DATABASES LIKE '{db_name}'")
            result = cursor.fetchone()
            cursor.close()
            temp_conn.close()
            return result is not None
        except Error as e:
            print(f"{Colors.RED}Error checking database: {e}{Colors.RESET}")
            return False

    def create_database(self) -> bool:
        try:
            temp_config = self.config.copy()
            db_name = temp_config.pop('database')
            temp_conn = mysql.connector.connect(**temp_config)
            cursor = temp_conn.cursor()
            cursor.execute(f"CREATE DATABASE IF NOT EXISTS {db_name}")
            cursor.close()
            temp_conn.close()
            return True
        except Error as e:
            print(f"{Colors.RED}Error creating database: {e}{Colors.RESET}")
            return False

    def create_table_if_not_exists(self):
        try:
            cursor = self.connection.cursor()
            cursor.execute(f"""
                CREATE TABLE IF NOT EXISTS {TABLE_NAME} (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    license_key VARCHAR(255) UNIQUE NOT NULL,
                    product VARCHAR(255) NOT NULL,
                    status VARCHAR(50) DEFAULT 'active',
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_license_key (license_key),
                    INDEX idx_product (product),
                    INDEX idx_status (status)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            """)
            self.connection.commit()
            cursor.close()
            return True
        except Error as e:
            print(f"{Colors.RED}Error creating table: {e}{Colors.RESET}")
            return False

    def delete_license(self, license_key: str) -> bool:
        try:
            cursor = self.connection.cursor()
            query = f"DELETE FROM {TABLE_NAME} WHERE license_key = %s"
            cursor.execute(query, (license_key,))
            self.connection.commit()
            affected = cursor.rowcount
            cursor.close()
            return affected > 0
        except Error as e:
            print(f"{Colors.RED}Error deleting license: {e}{Colors.RESET}")
            return False

    def get_distinct_products(self) -> List[str]:
        try:
            cursor = self.connection.cursor()
            cursor.execute(f"SELECT DISTINCT product FROM {TABLE_NAME}")
            rows = cursor.fetchall()
            cursor.close()
            return [row[0] for row in rows]
        except Error as e:
            print(f"{Colors.RED}Error fetching products: {e}{Colors.RESET}")
            return []

    def find_warning_keys(self, log_table: str = 'verification_logs', threshold: int = 2) -> List[tuple]:
        try:
            cursor = self.connection.cursor()
            query = (
                f"SELECT v.license_key, v.product, COUNT(*) as cnt "
                f"FROM {log_table} v "
                f"WHERE v.license_key IN (SELECT license_key FROM {TABLE_NAME}) "
                f"GROUP BY v.license_key, v.product "
                f"HAVING cnt >= %s"
            )
            cursor.execute(query, (threshold,))
            rows = cursor.fetchall()
            cursor.close()
            return [(row[0], row[1], int(row[2])) for row in rows]
        except Error as e:
            print(f"{Colors.RED}Error searching verification_logs: {e}{Colors.RESET}")
            return []

    def find_multiple_domain_keys(self, log_table: str = 'verification_logs', min_domains: int = 2) -> List[tuple]:
        try:
            cursor = self.connection.cursor()
            query = (
                f"SELECT v.license_key, v.product, COUNT(DISTINCT v.domain) as domain_count "
                f"FROM {log_table} v "
                f"WHERE v.license_key IN (SELECT license_key FROM {TABLE_NAME}) "
                f"GROUP BY v.license_key, v.product "
                f"HAVING domain_count >= %s"
            )
            cursor.execute(query, (min_domains,))
            rows = cursor.fetchall()
            cursor.close()
            return [(row[0], row[1], int(row[2])) for row in rows]
        except Error as e:
            print(f"{Colors.RED}Error searching for multi-domain keys: {e}{Colors.RESET}")
            return []

    def find_unused_keys(self, log_table: str = 'verification_logs') -> List[tuple]:
        try:
            cursor = self.connection.cursor()
            query = (
                f"SELECT l.license_key, l.product, l.created_at "
                f"FROM {TABLE_NAME} l "
                f"LEFT JOIN {log_table} v ON l.license_key = v.license_key "
                f"WHERE v.license_key IS NULL"
            )
            cursor.execute(query)
            rows = cursor.fetchall()
            cursor.close()
            return [(row[0], row[1], row[2]) for row in rows]
        except Error as e:
            print(f"{Colors.RED}Error searching for unused keys: {e}{Colors.RESET}")
            return []

    def clear_verification_logs(self, log_table: str = 'verification_logs') -> bool:
        try:
            cursor = self.connection.cursor()
            try:
                cursor.execute(f"TRUNCATE TABLE {log_table}")
            except Error:
                cursor.execute(f"DELETE FROM {log_table}")
            self.connection.commit()
            cursor.close()
            return True
        except Error as e:
            print(f"{Colors.RED}Error clearing verification_logs: {e}{Colors.RESET}")
            return False

    def insert_license(self, license_key: str, product: str, status: str = 'active') -> bool:
        try:
            cursor = self.connection.cursor()
            query = f"INSERT INTO {TABLE_NAME} (license_key, product, status) VALUES (%s, %s, %s)"
            cursor.execute(query, (license_key, product, status))
            self.connection.commit()
            cursor.close()
            return True
        except Error as e:
            print(f"{Colors.RED}Error inserting license: {e}{Colors.RESET}")
            return False

    def insert_multiple_licenses(self, licenses: List[tuple]) -> int:
        success_count = 0
        for license_key, product, status in licenses:
            if self.insert_license(license_key, product, status):
                success_count += 1
        return success_count

    def export_to_sql(self, licenses: List[str], filename: str = None) -> str:
        if filename is None:
            timestamp = datetime.now().strftime("%Y%m%d_%H%M%S")
            filename = f"database/exports/licenses_{timestamp}.sql"

        os.makedirs(os.path.dirname(filename), exist_ok=True)

        with open(filename, 'w') as f:
            f.write(f"-- License Keys Export\n")
            f.write(f"-- Generated: {datetime.now().strftime('%Y-%m-%d %H:%M:%S')}\n")
            f.write(f"-- Product: {PRODUCT_NAME}\n\n")
            f.write(f"USE {DB_CONFIG['database']};\n\n")

            for license_key in licenses:
                f.write(f"INSERT INTO {TABLE_NAME} (license_key, product, status) ")
                f.write(f"VALUES ('{license_key}', '{PRODUCT_NAME}', 'active');\n")

        return filename


class LicenseKeyCLI:
    def __init__(self):
        self.generator = LicenseKeyGenerator()
        self.db = LicenseDatabase(DB_CONFIG)
        self.terminal_width = self.get_terminal_width()
        self.ascii_banner = self.load_ascii_banner()
        self.products_file = os.path.join(os.path.dirname(__file__), 'products.json')

    def get_terminal_width(self) -> int:
        try:
            return os.get_terminal_size().columns
        except:
            return 80

    def load_ascii_banner(self) -> str:
        try:
            with open('assets/ascii.txt', 'r', encoding='utf-8') as f:
                return f.read()
        except:
            return "SAFETY BLUR"

    def center_text(self, text: str) -> str:
        import re
        self.terminal_width = self.get_terminal_width()
        lines = text.split('\n')
        centered = []
        for line in lines:
            ansi_escape = re.compile(r'\x1B(?:[@-Z\\-_]|\[[0-?]*[ -/]*[@-~])')
            clean_line = ansi_escape.sub('', line)
            padding = max((self.terminal_width - len(clean_line)) // 2, 0)
            centered.append(' ' * padding + line)
        return '\n'.join(centered)

    def clear_screen(self):
        os.system('cls' if os.name == 'nt' else 'clear')

    def display_header(self):
        print(f"\n{Colors.BLUE}{self.center_text(self.ascii_banner)}{Colors.RESET}")
        print(f"{self.center_text('License Key Generator')}\n")

    def display_main_menu(self):
        self.clear_screen()
        self.display_header()
        menu_items = [
            "",
            f"{Colors.CYAN}[1]{Colors.RESET} Generate Single License Key",
            f"{Colors.CYAN}[2]{Colors.RESET} Look for Warnings (possible reused keys)",
            f"{Colors.CYAN}[3]{Colors.RESET} View Unused License Keys",
            f"{Colors.CYAN}[4]{Colors.RESET} Add Test Product",
            f"{Colors.CYAN}[5]{Colors.RESET} Clear verification_logs table",
            f"{Colors.CYAN}[6]{Colors.RESET} Database Information",
            f"{Colors.CYAN}[7]{Colors.RESET} Exit",
            ""
        ]

        for item in menu_items:
            print(self.center_text(item))

    def generate_single_key(self):
        self.clear_screen()
        self.display_header()
        products_from_file = self.load_products()

        if products_from_file is None:
            products = [PRODUCT_NAME] if PRODUCT_NAME else []
        else:
            products = products_from_file

        chosen_product = PRODUCT_NAME

        if not products:
            print(self.center_text(f"{Colors.YELLOW}No products found in products.json.{Colors.RESET}\n"))
            print(self.center_text(f"[n] Enter a new product name"))
            print(self.center_text("\nEnter choice (n to add, or Enter to cancel): "), end='')
            sel = input().strip()
            if sel.lower() == 'n':
                print(self.center_text("Enter new product name: "), end='')
                newp = input().strip()
                if newp:
                    chosen_product = newp
                    try:
                        self.add_product_to_file(newp)
                    except Exception:
                        pass
                else:
                    print(self.center_text(f"\n{Colors.RED}No product entered. Aborting.{Colors.RESET}"))
                    input(self.center_text(f"\n{Colors.DIM}Press Enter to continue...{Colors.RESET}"))
                    return
            else:
                return
        else:
            print(self.center_text(f"{Colors.YELLOW}Select product for this license (press Enter to use default: {PRODUCT_NAME}):{Colors.RESET}\n"))
            for idx, p in enumerate(products, start=1):
                print(self.center_text(f"[{idx}] {p}"))
            print(self.center_text(f"[n] Enter a new product name"))
            print(self.center_text("\nEnter choice (number, n, or Enter): "), end='')
            sel = input().strip()
            if sel == '':
                chosen_product = products[0] if products else PRODUCT_NAME
            elif sel.lower() == 'n':
                print(self.center_text("Enter new product name: "), end='')
                newp = input().strip()
                if newp:
                    chosen_product = newp
                    try:
                        self.add_product_to_file(newp)
                    except Exception:
                        pass
            else:
                try:
                    i = int(sel) - 1
                    if 0 <= i < len(products):
                        chosen_product = products[i]
                except Exception:
                    chosen_product = products[0] if products else PRODUCT_NAME

        print(f"\n{self.center_text(f'{Colors.YELLOW}Generating license key for: {Colors.GREEN}{chosen_product}{Colors.RESET}')}")

        license_key = self.generator.generate_key()

        if self.db.insert_license(license_key, chosen_product):
            print(f"\n{self.center_text(f'{Colors.GREEN}✓ License Key Generated Successfully!{Colors.RESET}')}\n")
            print(self.center_text(f'{Colors.BOLD}{license_key}{Colors.RESET}'))
        else:
            print(f"\n{self.center_text(f'{Colors.RED}✗ Failed to insert license key into database{Colors.RESET}')}")

        input(f"\n{self.center_text(f'{Colors.DIM}Press Enter to continue...{Colors.RESET}')}")

    def generate_multiple_keys(self):
        self.clear_screen()
        self.display_header()
        print(f"\n{self.center_text(f'{Colors.YELLOW}Generate Multiple License Keys{Colors.RESET}')}\n")
        try:
            print(self.center_text("How many keys to generate? "), end='')
            count_input = input()
            count = int(count_input.strip())
            if count <= 0:
                print(f"\n{self.center_text(f'{Colors.RED}Please enter a positive number!{Colors.RESET}')}")
                input(f"\n{self.center_text(f'{Colors.DIM}Press Enter to continue...{Colors.RESET}')}")
                return
        except ValueError:
            print(f"\n{self.center_text(f'{Colors.RED}Invalid input!{Colors.RESET}')}")
            input(f"\n{self.center_text(f'{Colors.DIM}Press Enter to continue...{Colors.RESET}')}")
            return

        print(f"\n{self.center_text(f'{Colors.YELLOW}Generating {count} license keys for {Colors.GREEN}{PRODUCT_NAME}{Colors.RESET}...')}\n")
        keys = self.generator.generate_multiple_keys(count)
        licenses = [(key, PRODUCT_NAME, 'active') for key in keys]
        success_count = self.db.insert_multiple_licenses(licenses)

        print(f"{self.center_text(f'{Colors.GREEN}✓ Successfully generated {success_count} keys{Colors.RESET}')}\n")
        print(self.center_text("─" * 60))
        for key in keys:
            print(self.center_text(f"{Colors.BOLD}{key}{Colors.RESET}"))
        print(self.center_text("─" * 60))
        input(f"\n{self.center_text(f'{Colors.DIM}Press Enter to continue...{Colors.RESET}')}")

    def export_licenses(self):
        self.clear_screen()
        self.display_header()
        print(f"\n{self.center_text(f'{Colors.YELLOW}Export Licenses to SQL File{Colors.RESET}')}\n")
        try:
            print(self.center_text("How many keys to generate and export? "), end='')
            count_input = input()
            count = int(count_input.strip())
            if count <= 0:
                print(f"\n{self.center_text(f'{Colors.RED}Please enter a positive number!{Colors.RESET}')}")
                input(f"\n{self.center_text(f'{Colors.DIM}Press Enter to continue...{Colors.RESET}')}")
                return
        except ValueError:
            print(f"\n{self.center_text(f'{Colors.RED}Invalid input!{Colors.RESET}')}")
            input(f"\n{self.center_text(f'{Colors.DIM}Press Enter to continue...{Colors.RESET}')}")
            return

        keys = self.generator.generate_multiple_keys(count)
        filename = self.db.export_to_sql(keys)
        print(f"\n{self.center_text(f'{Colors.GREEN}✓ Exported {count} licenses to:{Colors.RESET}')}")
        print(f"{self.center_text(f'{Colors.CYAN}{filename}{Colors.RESET}')}\n")
        input(f"{self.center_text(f'{Colors.DIM}Press Enter to continue...{Colors.RESET}')}")

    def show_database_info(self):
        self.clear_screen()
        self.display_header()
        print(f"\n{self.center_text(f'{Colors.YELLOW}Database Information{Colors.RESET}')}\n")
        print(self.center_text("─" * 50))
        print(self.center_text(f"Host: {Colors.CYAN}{DB_CONFIG['host']}{Colors.RESET}"))
        print(self.center_text(f"Database: {Colors.CYAN}{DB_CONFIG['database']}{Colors.RESET}"))
        print(self.center_text(f"Table: {Colors.CYAN}{TABLE_NAME}{Colors.RESET}"))
        print(self.center_text(f"Product: {Colors.CYAN}{PRODUCT_NAME}{Colors.RESET}"))
        print(self.center_text(f"Status: {Colors.GREEN}Connected ✓{Colors.RESET}"))
        print(self.center_text("─" * 50))
        input(f"\n{self.center_text(f'{Colors.DIM}Press Enter to continue...{Colors.RESET}')}")

    def add_test_product(self):
        global PRODUCT_NAME, CONFIG
        self.clear_screen()
        self.display_header()
        print(self.center_text(f"{Colors.YELLOW}Add Test Product{Colors.RESET}\n"))
        print(self.center_text("Enter product name: "), end='')
        pname = input().strip()
        if not pname:
            print(self.center_text(f"{Colors.RED}No product name entered. Aborting.{Colors.RESET}"))
            input(self.center_text(f"\n{Colors.DIM}Press Enter to continue...{Colors.RESET}"))
            return

        try:
            self.add_product_to_file(pname)
        except Exception as e:
            print(self.center_text(f"{Colors.RED}Failed to add to products.json: {e}{Colors.RESET}"))

        CONFIG['product']['name'] = pname
        try:
            with open('config.json', 'w', encoding='utf-8') as f:
                json.dump(CONFIG, f, indent=4)
            PRODUCT_NAME = pname
            print(self.center_text(f"{Colors.GREEN}Product set to: {pname}{Colors.RESET}"))
        except Exception as e:
            print(self.center_text(f"{Colors.RED}Failed to update config.json: {e}{Colors.RESET}"))

        input(self.center_text(f"\n{Colors.DIM}Press Enter to continue...{Colors.RESET}"))

    def show_warnings(self):
        self.clear_screen()
        self.display_header()
        print(self.center_text(f"{Colors.YELLOW}Scanning for keys used on multiple domains...{Colors.RESET}\n"))

        findings = self.db.find_multiple_domain_keys()
        if not findings:
            print(self.center_text(f"{Colors.GREEN}No keys have been used on multiple domains.\n{Colors.RESET}"))
            input(self.center_text(f"\n{Colors.DIM}Press Enter to continue...{Colors.RESET}"))
            return

        print(self.center_text(f"{Colors.RED}The following license keys were used on multiple distinct domains and still exist in the licences table:{Colors.RESET}\n"))
        for idx, (key, product, domains) in enumerate(findings, start=1):
            print(self.center_text(f"[{idx}] Key: {key}  Product: {product}  Domains: {domains}"))

        print(self.center_text("\nOptions:"))
        print(self.center_text("[d] Delete one key by number"))
        print(self.center_text("[a] Delete all listed keys"))
        print(self.center_text("[n] Nothing / return to menu"))
        print(self.center_text("\nEnter choice: "), end='')
        choice = input().strip().lower()

        if choice == 'n' or choice == '':
            return
        if choice == 'a':
            deleted = 0
            for key, product, domains in findings:
                if self.db.delete_license(key):
                    deleted += 1
            print(self.center_text(f"\n{Colors.GREEN}Deleted {deleted} keys.{Colors.RESET}"))
        elif choice == 'd':
            print(self.center_text("Enter the number of the key to delete: "), end='')
            sel = input().strip()
            try:
                i = int(sel) - 1
                if 0 <= i < len(findings):
                    key_to_del = findings[i][0]
                    if self.db.delete_license(key_to_del):
                        print(self.center_text(f"\n{Colors.GREEN}Deleted key: {key_to_del}{Colors.RESET}"))
                    else:
                        print(self.center_text(f"\n{Colors.RED}Failed to delete key: {key_to_del}{Colors.RESET}"))
            except Exception:
                print(self.center_text(f"\n{Colors.RED}Invalid selection.{Colors.RESET}"))

        input(self.center_text(f"\n{Colors.DIM}Press Enter to continue...{Colors.RESET}"))

    def show_unused_keys(self):
        self.clear_screen()
        self.display_header()
        print(self.center_text(f"{Colors.YELLOW}Searching for unused license keys...{Colors.RESET}\n"))

        findings = self.db.find_unused_keys()
        if not findings:
            print(self.center_text(f"{Colors.GREEN}No unused license keys found.\n{Colors.RESET}"))
            input(self.center_text(f"\n{Colors.DIM}Press Enter to continue...{Colors.RESET}"))
            return

        print(self.center_text(f"{Colors.CYAN}The following license keys exist in the licences table but have never appeared in verification_logs:{Colors.RESET}\n"))
        for idx, (key, product, created_at) in enumerate(findings, start=1):
            created_str = str(created_at) if created_at is not None else ''
            print(self.center_text(f"[{idx}] Key: {key}  Product: {product}  Created: {created_str}"))

        input(self.center_text(f"\n{Colors.DIM}Press Enter to continue...{Colors.RESET}"))

    def load_products(self) -> List[str]:
        try:
            if not os.path.exists(self.products_file):
                return None

            with open(self.products_file, 'r', encoding='utf-8') as f:
                try:
                    data = json.load(f)
                    if isinstance(data, list):
                        return data
                    else:
                        return []
                except json.JSONDecodeError:
                    return []
        except Exception:
            return []

    def save_products(self, products: List[str]):
        with open(self.products_file, 'w', encoding='utf-8') as f:
            json.dump(products, f, indent=4)

    def add_product_to_file(self, product_name: str):
        products = self.load_products()
        if products is None:
            products = []
        if product_name not in products:
            products.append(product_name)
            self.save_products(products)

    def clear_verification_logs_flow(self):
        self.clear_screen()
        self.display_header()
        print(self.center_text(f"{Colors.YELLOW}Clear verification_logs (this will reset counts){Colors.RESET}\n"))

        alnum = string.ascii_letters + string.digits
        length = secrets.choice(list(range(6, 13)))
        challenge = ''.join(secrets.choice(alnum) for _ in range(length))
        print(self.center_text(f"Type the following string exactly to confirm:\n\n{Colors.CYAN}{challenge}{Colors.RESET}\n"))
        print(self.center_text("Enter string: "), end='')
        resp = input().strip()
        if resp != challenge:
            print(self.center_text(f"\n{Colors.RED}Confirmation failed. Aborting clear operation.{Colors.RESET}"))
            input(self.center_text(f"\n{Colors.DIM}Press Enter to continue...{Colors.RESET}"))
            return

        print(self.center_text("\nDo you want to delete product keys that were used on multiple domains? (y/n): "), end='')
        if input().strip().lower().startswith('y'):
            multi = self.db.find_multiple_domain_keys()
            if not multi:
                print(self.center_text(f"\n{Colors.GREEN}No multi-domain-used keys found.{Colors.RESET}"))
            else:
                print(self.center_text(f"\n{Colors.RED}The following keys were used on multiple domains and will be deleted:{Colors.RESET}\n"))
                for idx, (key, product, domains) in enumerate(multi, start=1):
                    print(self.center_text(f"[{idx}] Key: {key}  Product: {product}  Domains: {domains}"))
                print(self.center_text("\nConfirm deletion of these keys? (y/n): "), end='')
                if input().strip().lower().startswith('y'):
                    deleted = 0
                    for key, product, domains in multi:
                        if self.db.delete_license(key):
                            deleted += 1
                    print(self.center_text(f"\n{Colors.GREEN}Deleted {deleted} multi-domain keys.{Colors.RESET}"))

        print(self.center_text("\nConfirm clearing verification_logs table now? (y/n): "), end='')
        if not input().strip().lower().startswith('y'):
            print(self.center_text(f"\n{Colors.YELLOW}Aborted clearing verification_logs.{Colors.RESET}"))
            input(self.center_text(f"\n{Colors.DIM}Press Enter to continue...{Colors.RESET}"))
            return

        if self.db.clear_verification_logs():
            print(self.center_text(f"\n{Colors.GREEN}verification_logs cleared successfully.{Colors.RESET}"))
        else:
            print(self.center_text(f"\n{Colors.RED}Failed to clear verification_logs.{Colors.RESET}"))

        input(self.center_text(f"\n{Colors.DIM}Press Enter to continue...{Colors.RESET}"))

    def run(self):
        print(f"{Colors.YELLOW}Checking database...{Colors.RESET}")
        if not self.db.database_exists():
            print(f"{Colors.YELLOW}Database doesn't exist. Creating...{Colors.RESET}")
            self.db.create_database()

        print(f"{Colors.YELLOW}Connecting to database...{Colors.RESET}")
        if not self.db.connect():
            print(f"{Colors.RED}Failed to connect to database. Please check your configuration.{Colors.RESET}")
            return

        self.db.create_table_if_not_exists()
        print(f"{Colors.GREEN}Database ready!{Colors.RESET}")
        input(f"\n{Colors.DIM}Press Enter to continue...{Colors.RESET}")

        while True:
            self.display_main_menu()
            print(self.center_text("Enter choice: "), end='')
            choice = input().strip()

            if choice == '1':
                self.generate_single_key()
            elif choice == '2':
                self.show_warnings()
            elif choice == '3':
                self.show_unused_keys()
            elif choice == '4':
                self.add_test_product()
            elif choice == '5':
                self.clear_verification_logs_flow()
            elif choice == '6':
                self.show_database_info()
            elif choice == '7':
                self.clear_screen()
                print(f"\n{self.center_text(f'{Colors.GREEN}Thank you for using Safety Blur License Generator!{Colors.RESET}')}\n")
                self.db.disconnect()
                break
            else:
                print(f"\n{self.center_text(f'{Colors.RED}Invalid choice! Please try again.{Colors.RESET}')}")
                input(f"\n{self.center_text(f'{Colors.DIM}Press Enter to continue...{Colors.RESET}')}")


if __name__ == "__main__":
    cli = LicenseKeyCLI()
    cli.run()
