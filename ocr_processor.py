import sys
import os
import logging
from datetime import datetime
import pandas as pd
from google import genai
from PIL import Image
import fitz  # PyMuPDF
import shutil
import time
import json
from pathlib import Path
import cv2
import numpy as np
import argparse

# --- HARDCODED API KEYS ---
# Please replace the placeholder values with your actual API keys.
HARDCODED_API_KEY_1 = "AIzaSyBAlWQ5oMxUxDiZHxW5f1AMS2uOOMC1UdY"  # For the top third of the image
HARDCODED_API_KEY_2 = "AIzaSyBcVmhC0um5ff__jRc4pPeOCorQNwOhynU"  # For the middle third of the image
HARDCODED_API_KEY_3 = "AIzaSyC0gI13g7Sf-3QqI-l_pJC18iWJCFrVbck"  # For the bottom third of the image
# ---------------------------

# --- CONFIGURATION ---
CONFIG = {
    "LOG_FILE": Path("ocr_extraction_log.txt"),
    "TEMP_IMAGE_DIR": Path("temp_ocr_images"),
    "PREPROCESSED_DIR": Path("temp_ocr_images/preprocessed"),
    "GEMINI_MODEL": "gemini-2.0-flash",
    "MAX_RETRIES": 3,
    "RETRY_DELAY": 5,
}

logging.basicConfig(level=logging.INFO,
                    format='%(asctime)s - %(levelname)s - %(message)s',
                    handlers=[logging.FileHandler(CONFIG["LOG_FILE"], mode='w')])
logger = logging.getLogger(__name__)

class OcrConverter:
    """
    Extracts and standardizes tabular data from PDFs/images using Gemini API.
    """
    def __init__(self, api_key1: str, api_key2: str, api_key3: str):
        self.model = CONFIG["GEMINI_MODEL"]
        # Standard target columns that the PHP script expects.
        self.target_columns = [
            "Name", "MobileNo", "Pan", "DOB", "Address", "City", "State", "Pincode"
        ]
        try:
            self.client1 = genai.Client(api_key=api_key1)
            self.client2 = genai.Client(api_key=api_key2)
            self.client3 = genai.Client(api_key=api_key3)
            logger.info("Gemini API clients configured successfully.")
        except Exception as e:
            logger.error(f"Failed to configure Gemini API clients. Error: {e}")
            raise

    def _create_temp_dirs(self):
        try:
            for dir_path in [CONFIG["TEMP_IMAGE_DIR"], CONFIG["PREPROCESSED_DIR"]]:
                if dir_path.exists():
                    shutil.rmtree(dir_path)
                dir_path.mkdir(parents=True, exist_ok=True)
            logger.info("Temporary directories created.")
        except Exception as e:
            logger.error(f"Could not create temporary directories: {e}")
            raise

    def convert_source_to_images(self, input_file: Path) -> list[Path]:
        if not input_file.exists():
            logger.error(f"Input file not found at '{input_file}'")
            return []
        
        if input_file.suffix.lower() in ['.png', '.jpg', '.jpeg']:
            return [input_file]

        if input_file.suffix.lower() == '.pdf':
            image_paths = []
            try:
                doc = fitz.open(input_file)
                for page_num in range(len(doc)):
                    page = doc.load_page(page_num)
                    pix = page.get_pixmap(dpi=300)
                    image_path = CONFIG["TEMP_IMAGE_DIR"] / f"page_{page_num + 1}.png"
                    pix.save(str(image_path))
                    image_paths.append(image_path)
                return image_paths
            except Exception as e:
                logger.error(f"Failed to convert PDF to images: {e}")
                return []
        
        logger.error(f"Unsupported file type: {input_file.suffix}")
        return []

    def preprocess_image(self, input_path: Path, output_path: Path):
        try:
            image = cv2.imread(str(input_path))
            if image is None: return
            
            gray = cv2.cvtColor(image, cv2.COLOR_BGR2GRAY)
            denoised = cv2.fastNlMeansDenoising(gray, None, 10, 7, 21)
            processed_image = cv2.adaptiveThreshold(
                denoised, 255, cv2.ADAPTIVE_THRESH_GAUSSIAN_C,
                cv2.THRESH_BINARY, 11, 2
            )
            cv2.imwrite(str(output_path), processed_image)
            logger.info(f"Preprocessing complete for {input_path.name}.")
        except Exception as e:
            logger.error(f"Error during preprocessing of {input_path.name}: {e}")

    def _get_gemini_prompt(self) -> str:
        target_columns_str = ", ".join(f'"{h}"' for h in self.target_columns)
        return f"""
Analyze the provided image. Your task is to extract tabular data and map it directly to a standard JSON format.

**Instructions:**
1.  Your output MUST be a single, valid JSON object. Do not include any other text, explanations, or markdown like ```json.
2.  The JSON object must have one key: "data".
3.  The value for "data" must be an array of objects, where each object represents a single row.
4.  For each row, identify the data and map it to the most appropriate key from this standard list: {target_columns_str}.
5.  Example of a single object in the array: {{"Name": "John Doe", "MobileNo": "9876543210", "Address": "123 Main St", "Pan": "ABCDE1234F", "DOB": "1990-01-15", "City": "Mumbai", "State": "MH", "Pincode": "400001"}}
6.  If a value for a standard column is not found in a row, the key must still be present with an empty string "" as its value.
7.  It is CRITICAL to maintain row integrity. All data for one person or record must be in the same JSON object.
8.  If the image contains no discernible table data, return an empty JSON object: {{"data": []}}.
"""

    def extract_data_from_image_part(self, image_path: Path, client: genai.Client) -> pd.DataFrame | None:
        for attempt in range(CONFIG["MAX_RETRIES"]):
            try:
                logger.info(f"Processing {image_path.name} with Gemini... (Attempt {attempt + 1})")
                image_file = Image.open(image_path)
                prompt = self._get_gemini_prompt()
                
                response = client.models.generate_content(model=self.model, contents=[image_file, prompt])
                
                response_text = response.text.strip()
                json_start = response_text.find('{')
                json_end = response_text.rfind('}')
                if json_start == -1 or json_end == -1:
                    continue
                
                json_text = response_text[json_start:json_end+1]
                json_data = json.loads(json_text)

                if json_data and "data" in json_data and isinstance(json_data["data"], list):
                    logger.info(f"Successfully received and parsed JSON from Gemini for {image_path.name}.")
                    df = pd.DataFrame(json_data["data"])
                    return df if not df.empty else None
            except (json.JSONDecodeError, Exception) as e:
                logger.error(f"Error processing Gemini response for {image_path.name} (Attempt {attempt + 1}): {e}")
                logger.debug(f"Problematic Text: {response_text}")

            if attempt < CONFIG["MAX_RETRIES"] - 1:
                time.sleep(CONFIG['RETRY_DELAY'])
        return None

    def cleanup(self):
        try:
            if CONFIG["TEMP_IMAGE_DIR"].exists():
                shutil.rmtree(CONFIG["TEMP_IMAGE_DIR"])
            logger.info("Temporary OCR directory cleaned up.")
        except Exception as e:
            logger.error(f"Failed to clean up temporary OCR directory: {e}")

    def run(self, input_file: Path, output_csv: Path):
        start_time = datetime.now()
        logger.info(f"--- OCR Automation Started: {start_time.strftime('%Y-%m-%d %H:%M:%S')} ---")
        try:
            self._create_temp_dirs()
            source_image_paths = self.convert_source_to_images(input_file)
            if not source_image_paths:
                return

            self.all_data_frames = []
            for i, raw_path in enumerate(source_image_paths):
                preprocessed_path = CONFIG["PREPROCESSED_DIR"] / f"preprocessed_{raw_path.name}"
                self.preprocess_image(raw_path, preprocessed_path)
                
                if not preprocessed_path.exists(): continue

                full_image = cv2.imread(str(preprocessed_path))
                if full_image is None: continue
                
                height = full_image.shape[0]
                part_height = height // 3
                
                part_paths = []
                part_images = [full_image[0:part_height, :], full_image[part_height:2*part_height, :], full_image[2*part_height:height, :]]

                for j, part_img in enumerate(part_images):
                    part_path = CONFIG["PREPROCESSED_DIR"] / f"p{i+1}_part{j+1}_{preprocessed_path.name}"
                    cv2.imwrite(str(part_path), part_img)
                    part_paths.append(part_path)

                page_dfs = []
                clients = [self.client1, self.client2, self.client3]
                for j, part_path in enumerate(part_paths):
                    df_part = self.extract_data_from_image_part(part_path, clients[j])
                    if df_part is not None:
                        page_dfs.append(df_part)
                
                if page_dfs:
                    self.all_data_frames.append(pd.concat(page_dfs, ignore_index=True))

            if not self.all_data_frames:
                logger.warning("No data was extracted from the document.")
                output_csv.touch()
                return

            final_df = pd.concat(self.all_data_frames, ignore_index=True)
            
            for col in self.target_columns:
                if col not in final_df.columns:
                    final_df[col] = ""
            
            final_df = final_df[self.target_columns]

            final_df.to_csv(output_csv, index=False, quoting=1)
            logger.info(f"SUCCESS: Standardized data saved to '{output_csv}'")

        except Exception as e:
            logger.error(f"A fatal error occurred: {e}", exc_info=True)
        finally:
            self.cleanup()
            end_time = datetime.now()
            logger.info(f"--- Automation Finished. Duration: {end_time - start_time} ---")

if __name__ == "__main__":
    parser = argparse.ArgumentParser(description="Extract table data from a PDF or image file using Gemini OCR.")
    parser.add_argument("input_file", help="Path to the input PDF or image file.")
    parser.add_argument("output_csv", help="Path to save the output CSV file.")
    args = parser.parse_args()

    if "REPLACE_WITH" in HARDCODED_API_KEY_1:
        logger.error("API keys are not set. Please edit ocr_processor.py and replace placeholders.")
        sys.exit(1)

    converter = OcrConverter(api_key1=HARDCODED_API_KEY_1, api_key2=HARDCODED_API_KEY_2, api_key3=HARDCODED_API_KEY_3)
    converter.run(Path(args.input_file), Path(args.output_csv))
