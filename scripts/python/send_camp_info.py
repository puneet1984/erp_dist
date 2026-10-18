import json
import os
from datetime import datetime
import requests
import logging
from dotenv import load_dotenv

# Load environment variables
load_dotenv()

# Configure logging and files
logging.basicConfig(
    level=logging.INFO,
    format='%(asctime)s - %(levelname)s - %(message)s',
    # filename='/var/log/app/camp_alerts.log'
)

HISTORY_FILE = '/home/puneet/www/erp/assets/json/camp_marketing/message_history.json'
TEMPLATE_FILE = '/home/puneet/www/erp/assets/json/camp_marketing/message_templates.json'
WAHA_URL = os.getenv('WAHA_API_URL', 'http://localhost:5002')

def load_message_history():
    """Load message history from JSON file"""
    if os.path.exists(HISTORY_FILE):
        with open(HISTORY_FILE, 'r') as f:
            return json.load(f)
    return {}

def save_message_history(history):
    """Save message history to JSON file"""
    os.makedirs(os.path.dirname(HISTORY_FILE), exist_ok=True)
    with open(HISTORY_FILE, 'w') as f:
        json.dump(history, f, indent=2)

def load_message_template():
    """Load message template from JSON file"""
    if not os.path.exists(TEMPLATE_FILE):
        raise FileNotFoundError(f"Message template file not found: {TEMPLATE_FILE}")
    with open(TEMPLATE_FILE, 'r') as f:
        templates = json.load(f)
    return templates.get('health_camp', {}).get('template', '')

def send_whatsapp_message(phone, message):
    """Send WhatsApp message using WAHA API"""
    try:
        response = requests.post(
            f"{WAHA_URL}/send-message",
            json={"phone": phone, "message": message}
        )
        response.raise_for_status()
        return True, None
    except requests.RequestException as e:
        error = f"WhatsApp API Error: {str(e)}"
        logging.error(error)
        return False, error

# def check_whatsapp_number(phone):
#     """Check if number exists on WhatsApp"""
#     try:
#         response = requests.get(
#             f"{WAHA_URL}/contacts/check-exists",
#             params={"phone": phone}
#         )
#         response.raise_for_status()
#         result = response.json()
#         logging.info(f"Number check response: {result}")  # Debug logging
#         response_data = result.get('data', {})
#         return response_data.get('numberExists', False)
#     except requests.RequestException as e:
#         error = f"Number check failed: {str(e)}"
#         logging.error(error)
#         return False

def send_camp_info(patients):
    """Send health camp information to patients"""
    history = load_message_history()
    
    try:
        camp_message = load_message_template()
    except FileNotFoundError as e:
        logging.error(f"Template Error: {e}")
        return {'success': [], 'failed': [], 'invalid': []}
    
    results = {
        'success': [],
        'failed': [],
        'invalid': []
    }
    
    for patient in patients:
        patient_id = str(patient['patient_id'])
        
        if patient_id in history:
            continue
            
        # if not check_whatsapp_number(patient['phone']):
        #     results['invalid'].append(patient_id)
        #     logging.info(f"Patient {patient_id} phone not on WhatsApp")
        #     continue
            
        message = camp_message.format(name=patient['name'])
        success, error = send_whatsapp_message(patient['phone'], message)
        
        if success:
            # Added name to history record
            history[patient_id] = {
                'sent_at': datetime.now().isoformat(),
                'phone': patient['phone'],
                'name': patient['name']  # Added this line
            }
            results['success'].append(patient_id)
            logging.info(f"Message sent successfully to patient {patient_id}")
        else:
            results['failed'].append(patient_id)
            logging.error(f"Failed to send message to patient {patient_id}: {error}")
    
    save_message_history(history)
    return results

if __name__ == "__main__":
    # Example usage
    test_patients = [
        {
            'patient_id': '1001',
            'name': 'John Doe',
            'phone': '9827222070'
        },
        {
            'patient_id': '1002',
            'name': 'Jane Smith',
            'phone': '7828485666'
        }
    ]
    
    results = send_camp_info(test_patients)
    print(f"Messages sent successfully to: {results['success']}")
    print(f"Failed to send messages to: {results['failed']}")
    print(f"Invalid numbers (not on WhatsApp): {results['invalid']}")
