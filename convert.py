#!/usr/bin/env python3
# coding: utf-8
from typing import Dict

import lxml.etree as etree

parser = etree.XMLParser(strip_cdata=False)  # WP has CDATA xml tags


def parse_xml(xml_file):
    tree = etree.parse(xml_file, parser=parser)
    print("Dump parsed. Beginning changes.")
    root = tree.getroot()
    namespaces: Dict[str, str] = {
        'wp': 'http://wordpress.org/export/1.2/',
        'content': "http://purl.org/rss/1.0/modules/content/"
    }
    all_items = root.findall('./channel/item')
    total = len(all_items)
    for idx,item in enumerate(all_items):
        print('Post ' + str(idx+1) + '/' + str(total))
        #if idx > 5:
        #    return etree.tostring(root, pretty_print=True)
        # change posttype from profiles to person
        change_cdata(item.find('wp:post_type', namespaces), 'person', 'profiles')
        
        # append order_by_name field
        step_concat_name(item, namespaces)

        # change bio to be main content
        step_biography_to_main_content(item, namespaces)
        
        # job title
        postmeta_change(item, namespaces, 'position', 'person_jobtitle', 'field_156', 'field_5953aa3d25c14')
        
        # phone
        postmeta_change(item, namespaces, 'phone', 'person_phone_numbers_0_number', 'field_158', 'field_5953aaca25c16')
        
        # fax
        postmeta_change(item, namespaces, 'fax', 'person_phone_numbers_1_number', 'field_159', 'field_5953aaca25c16')
        
        # email
        postmeta_change(item, namespaces, 'email', 'person_email', 'field_160', 'field_5953ac93b95b5')
        
        # office location
        postmeta_change(item, namespaces, 'office_address', 'person_room', 'field_157', 'field_5953acb0b95b6')

        # education/specialties
        postmeta_change(item, namespaces, 'education', 'person_educationspecialties', 'field_162', 'field_5953acb0b95b6')
        
    return etree.tostring(root, pretty_print=True)
    
    
    #print(etree.tostring(root, pretty_print=True))

# changes a postmeta label and key from old profile system to match up with new profile system
def postmeta_change(item_element, namespaces, old_label, new_label, old_ACF_key, new_ACF_key):
    for child in item_element.findall('wp:postmeta/wp:meta_key', namespaces):
        
        change_cdata(child, new_label, old_label)
        changed_underscore_key = change_cdata(child, '_' + new_label, '_' + old_label)
        if changed_underscore_key:
            child.xpath('../wp:meta_value', namespaces=namespaces)[0].text = etree.CDATA(new_ACF_key)


# concatonates separate name fields into one order_by field
# removes first_name, middle_initial, last_name
def step_concat_name(item, namespaces):
    # append order_by_name field
    fname = get_cdata_val(item, 'first_name', namespaces)
    if not fname:
        fname = get_cdata_val(item, 'avf_first_name_1', namespaces)
    mname = get_cdata_val(item, 'middle_initial', namespaces)
    if not mname:
        mname = get_cdata_val(item, 'avf_middle_initial_1', namespaces)
    lname = get_cdata_val(item, 'last_name', namespaces)
    if not lname:
        lname = get_cdata_val(item, 'avf_last_name_1', namespaces)
    
    fullname = ''
    sortname = ''
    if fname:
        fullname += fname
        sortname += fname
    if mname:
        fullname += ' ' + mname
        sortname += ' ' + mname
    if lname:
        fullname += ' ' + lname
        sortname = lname + ', ' + sortname
    
    item.append(cdata_element_to_append('person_orderby_name', sortname))
    item.append(cdata_element_to_append('_person_orderby_name', 'field_59669002f433d'))

    #delete_postmeta(item, 'first_name', namespaces)
    #delete_postmeta(item, 'middle_initial', namespaces)
    #delete_postmeta(item, 'last_name', namespaces)
    #delete_postmeta(item, 'avf_first_name_1', namespaces)
    #delete_postmeta(item, 'avf_middle_initial_1', namespaces)
    #delete_postmeta(item, 'avf_last_name_1', namespaces)

# moves biography data to the main content field
def step_biography_to_main_content(item, namespaces):
    bio = get_cdata_val(item, 'biography', namespaces)
    
    change_cdata(item.find('content:encoded', namespaces), bio)


# gets the cdata value from a sub-element of the specified element. the sub-element is matched against text provided
def get_cdata_val(element, name, namespaces):
    el = element.xpath('./wp:postmeta[wp:meta_key[text()="' + name + '"]]/wp:meta_value/text()',
                       namespaces=namespaces)
    if el:
        return el[0]
    else:
        return ""


# creates a lxml element of wp:postmeta, with wp:meta_key and wp:meta_value children,
# and with cdata values defined by parameters
def cdata_element_to_append(name, value):
    wp_prefix = '{http://wordpress.org/export/1.2/}'
    el_postmeta = etree.Element(wp_prefix + "postmeta")
    el_meta_key = etree.SubElement(el_postmeta, wp_prefix + "meta_key")
    el_meta_key.text = etree.CDATA(name)
    el_meta_value = etree.SubElement(el_postmeta, wp_prefix + "meta_value")
    el_meta_value.text = etree.CDATA(value)
    
    return el_postmeta


# returns an element with cdata text changed
def change_cdata(element, newtext, oldtext = None):
    # print(element.text)
    if oldtext:
        if element.text.lower() == oldtext: # only replace the element if oldtext matches; parent is looping
            #print(element.text)
            element.text = etree.CDATA(newtext)
            return True
    else: #oldtext isn't defined; blindly replace the element given
        element.text = etree.CDATA(newtext)
        return True
    #print(element.text)
    return False


# changes the meta_value for a specified meta_key - used mainly for ACF backend field unique ids, stored in the value.
#def change_cdata_val(element, newvalue, oldvalue):
#    element.xpath('../wp:meta_value').text = etree.CDATA(newvalue)
    

# deletes a postmeta element which contains a child element matching the name provided
# also deletes the matching postmeta field that has a prepended underscore
def delete_postmeta(element, name, namespaces):
    for to_delete in element.xpath('./wp:postmeta[wp:meta_key[text()="' + name + '"]]', namespaces=namespaces):
        to_delete.getparent().remove(to_delete)

    for to_delete in element.xpath('./wp:postmeta[wp:meta_key[text()="_' + name + '"]]', namespaces=namespaces):
        to_delete.getparent().remove(to_delete)

# def change_tag(element, newtag):


with open('dump.xml') as xmldump:
    output_xml_string = parse_xml(xmldump)
with open('output.xml', 'wb') as output:
    output.write(output_xml_string)
